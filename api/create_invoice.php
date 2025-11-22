<?php
// api/create_invoice.php

// --- UTHIBITISHAJI ---
session_start(); // Anzisha session

// Hakikisha user ame-login
if (!isset($_SESSION['user_id'])) {
    http_response_code(401); // 401 Unauthorized
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please login again.']);
    exit();
}
// Pata user_id kutoka kwenye SESSION
$user_id = $_SESSION['user_id']; // HII ITAHITAJIKA KWA MAILER
// --- MWISHO WA UTHIBITISHAJI ---

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php'; // Hakikisha config ipo
require_once __DIR__ . '/mailer_config.php';
require_once __DIR__ . '/invoice_templates.php'; 

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

// Safisha data
$document_type = ucfirst(trim($data['document_type'] ?? 'Invoice'));
$customer_id = filter_var($data['customer_id'] ?? null, FILTER_VALIDATE_INT);
$contact_id = filter_var($data['contact_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$issue_date = $data['issue_date'] ?? date('Y-m-d');
$due_date = !empty($data['due_date']) ? $data['due_date'] : null;
$items = $data['items'] ?? [];
$tax_rate = (float)($data['tax_rate'] ?? 0.00);
$notes = trim($data['notes'] ?? '');
$payment_info = trim($data['payment_method_info'] ?? '');

if (empty($customer_id) || empty($items)) {
    echo json_encode(['status' => 'error', 'message' => 'Customer and at least one item are required.']);
    exit();
}

// --- Ulinzi wa Mwaka wa Kifedha ---
$financial_year_to_check = date('Y', strtotime($issue_date));
if (isset($pdo) && $pdo) {
    $stmt_year = $pdo->prepare("SELECT is_closed FROM financial_years WHERE year = ?");
    $stmt_year->execute([$financial_year_to_check]);
    $year_status = $stmt_year->fetch(PDO::FETCH_ASSOC);
    if ($year_status && $year_status['is_closed']) {
        http_response_code(403); // Forbidden
        echo json_encode(['status' => 'error', 'message' => "Financial year {$financial_year_to_check} is closed and cannot be modified."]);
        exit();
    }
}
// --- Mwisho wa Ulinzi ---

$invoice_id = null;
$pdf_path = '';
$pdf_filename = '';
$email_to_send = '';
$name_to_send = '';
$total_amount = 0;
$settings = []; 

try {
    // Hakikisha $pdo ipo
    if (!isset($pdo) || !$pdo) {
        throw new Exception("Database connection failed ('pdo' variable is null). Check api/db.php credentials.");
    }
    
    $pdo->beginTransaction();

    // 1. Tengeneza namba ya dokument
    $prefixMap = [
        'Invoice' => 'INV',
        'Quotation' => 'QUO',
        'Receipt' => 'RCPT',
        'Delivery Note' => 'DN',
        'Estimate' => 'EST',
        'Purchase Order' => 'PO',
        'Tax Invoice' => 'TINV',
        'Proforma Invoice' => 'PINV',
    ];
    $prefix = $prefixMap[$document_type] ?? 'DOC';

    $stmt_num = $pdo->query("SELECT MAX(id) AS max_id FROM invoices");
    $last_id = $stmt_num->fetchColumn();
    $next_id = ($last_id ?: 0) + 1;
    $invoice_number = $prefix . "-" . date('Y') . "-" . str_pad($next_id, 4, '0', STR_PAD_LEFT);

    // 2. Hifadhi ankara ya awali
    $stmt_inv = $pdo->prepare("
        INSERT INTO invoices (customer_id, contact_id, user_id, invoice_number, document_type, status, issue_date, due_date, tax_rate, notes, payment_method_info)
        VALUES (?, ?, ?, ?, ?, 'Draft', ?, ?, ?, ?, ?)
    ");
    $stmt_inv->execute([$customer_id, $contact_id, $user_id, $invoice_number, $document_type, $issue_date, $due_date, $tax_rate, $notes, $payment_info]);
    $invoice_id = $pdo->lastInsertId();

    // 3. Hifadhi items na kokotoa jumla
    $subtotal = 0;
    $stmt_item = $pdo->prepare("
        INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, total)
        VALUES (?, ?, ?, ?, ?)
    ");
    foreach ($items as $item) {
        $qty = (float)($item['quantity'] ?? 0);
        $price = (float)($item['unit_price'] ?? 0);
        $description = trim($item['description'] ?? 'N/A');

        if ($qty <= 0 || $price < 0 || empty($description)) {
             throw new Exception("Invalid item data detected: Description, Quantity (>0), and Price (>=0) are required.");
        }
        $total = $qty * $price;
        $stmt_item->execute([$invoice_id, $description, $qty, $price, $total]);
        $subtotal += $total;
    }

    // 4. Hesabu kodi na jumla kuu
    $tax_amount = $subtotal * ($tax_rate / 100);
    $total_amount = $subtotal + $tax_amount;

    // 5. Kokotoa balance na amount_paid (hasa kwa risiti)
    $balance_due = $total_amount;
    $amount_paid = 0;
    if ($document_type === 'Receipt') {
        $balance_due = 0;
        $amount_paid = $total_amount;
    }

    // 6. Sasisha ankara na total amounts
    $stmt_update = $pdo->prepare("
        UPDATE invoices SET subtotal = ?, tax_amount = ?, total_amount = ?, balance_due = ?, amount_paid = ? WHERE id = ?
    ");
    $stmt_update->execute([$subtotal, $tax_amount, $total_amount, $balance_due, $amount_paid, $invoice_id]);

    // 6. Pata taarifa zote za kuweka kwenye PDF
    // --- REKEBISHO LA 3.1: Pata settings za mtumiaji huyu (tenant) ---
    // (Tunatumia 'user_id' badala ya 'id = 1' iliyokuwa 'hardcoded')
    $stmt_settings = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt_settings->execute([$user_id]);
    $settings = $stmt_settings->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        // Fallback ikiwa user hayupo (ingawa haipaswi kutokea)
        $settings = ['business_name' => 'Your Company', 'default_invoice_template' => 'default', 'default_currency' => 'TZS'];
    }
    
    $stmt_cust = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt_cust->execute([$customer_id]);
    $customer_main_info = $stmt_cust->fetch(PDO::FETCH_ASSOC);
    if (!$customer_main_info) throw new Exception("Customer not found.");

    $contact_info = []; 
    if ($contact_id) {
       $stmt_contact = $pdo->prepare("SELECT * FROM contacts WHERE id = ? AND customer_id = ?");
       $stmt_contact->execute([$contact_id, $customer_id]);
       $contact_info = $stmt_contact->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    $invoice_data_db = $pdo->query("SELECT * FROM invoices WHERE id = $invoice_id")->fetch(PDO::FETCH_ASSOC);
    $items_data_db = $pdo->query("SELECT * FROM invoice_items WHERE invoice_id = $invoice_id")->fetchAll(PDO::FETCH_ASSOC);

    // If this is a converted receipt, get the original invoice number
    if ($document_type === 'Receipt' && !empty($invoice_data_db['converted_from_id'])) {
        $stmt_orig = $pdo->prepare("SELECT invoice_number FROM invoices WHERE id = ?");
        $stmt_orig->execute([$invoice_data_db['converted_from_id']]);
        $original_invoice_number = $stmt_orig->fetchColumn();
        if ($original_invoice_number) {
            $invoice_data_db['original_invoice_number'] = $original_invoice_number;
        }
    }

    // Chagua email ya kutuma (Bado inampa kipaumbele contact person)
    $email_to_send = !empty($contact_info['email']) ? $contact_info['email'] : ($customer_main_info['email'] ?? '');
    
    // Chagua jina la salamu (SASA DAIMA NI JINA LA MTEJA)
    $name_to_send = $customer_main_info['name']; 
    
    // 7. Tengeneza PDF kwa kutumia template maalum kulingana na aina ya waraka
    $pdf_html = '';
    switch ($document_type) {
        case 'Invoice':
        case 'Tax Invoice':
            $pdf_html = get_tax_invoice_template_html($settings, $customer_main_info, $contact_info, $invoice_data_db, $items_data_db);
            break;
        case 'Receipt':
            $pdf_html = get_receipt_template_html($settings, $customer_main_info, $contact_info, $invoice_data_db, $items_data_db);
            break;
        case 'Quotation':
        case 'Estimate':
            $pdf_html = get_quotation_template_html($settings, $customer_main_info, $contact_info, $invoice_data_db, $items_data_db);
            break;
        case 'Proforma Invoice':
            $pdf_html = get_proforma_invoice_template_html($settings, $customer_main_info, $contact_info, $invoice_data_db, $items_data_db);
            break;
        case 'Delivery Note':
            $pdf_html = get_delivery_note_template_html($settings, $customer_main_info, $contact_info, $invoice_data_db, $items_data_db);
            break;
        case 'Purchase Order':
            $pdf_html = get_purchase_order_template_html($settings, $customer_main_info, $contact_info, $invoice_data_db, $items_data_db);
            break;
        default:
            // Rejea kwenye template ya zamani kama hakuna maalum
            $pdf_html = get_default_template_html($settings, $customer_main_info, $contact_info, $invoice_data_db, $items_data_db);
            break;
    }

    // Endelea na utengenezaji wa PDF
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetAutoPageBreak(TRUE, 10);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 10);
    $pdf->writeHTML($pdf_html, true, false, true, false, ''); 

    $upload_dir = __DIR__ . '/../uploads/customer_invoices/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0775, true);

    $pdf_filename = strtolower(str_replace(' ', '_', $document_type)) . '_' . str_replace('-', '_', $invoice_number) . '_' . time() . '.pdf';
    $pdf_path = $upload_dir . $pdf_filename;
    $pdf->Output($pdf_path, 'F');
    $pdf_url = 'uploads/customer_invoices/' . $pdf_filename;

    // 8. Sasisha ankara na PDF URL na status
    $finalStatusMap = [
        'Invoice' => 'Unpaid',
        'Quotation' => 'Sent',
        'Receipt' => 'Paid',
        'Delivery Note' => 'Delivered',
        'Estimate' => 'Sent',
        'Purchase Order' => 'Sent',
        'Tax Invoice' => 'Unpaid',
        'Proforma Invoice' => 'Sent',
    ];
    $finalStatus = $finalStatusMap[$document_type] ?? 'Sent';

    $stmt_pdf = $pdo->prepare("UPDATE invoices SET pdf_url = ?, status = ? WHERE id = ?");
    $stmt_pdf->execute([$pdf_url, $finalStatus, $invoice_id]);

    $pdo->commit();

} catch (Throwable $e) { // Tumebadilisha iwe Throwable
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Invoice Creation Error: " . $e->getMessage() . " Data: " . json_encode($data));
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => "Failed to create {$document_type}: " . $e->getMessage()]);
    exit();
}

// 9. Tuma Email
if ($document_type === 'Receipt' || !empty($email_to_send)) {
    try {
        if (empty($email_to_send)) {
            $email_to_send = $customer_main_info['email'] ?? '';
            if (empty($email_to_send)) {
                throw new Exception("No email address found for the customer or contact.");
            }
        }

        // --- REKEBISHO LA 3.2: Pitisha '$user_id' ya tenant ---
        $mail = getMailerInstance($pdo, $user_id);
        
        $mail->addAddress($email_to_send, $name_to_send);
        
        $currency_symbol = $settings['default_currency'] ?? 'TZS';

        $mail->isHTML(true);
        
        if ($document_type === 'Receipt') {
            $mail->Subject = "Your Payment Receipt ({$invoice_number}) from {$settings['business_name']}";
        } else {
            $mail->Subject = "Your {$document_type} ({$invoice_number}) from {$settings['business_name']}";
        }
        
        $showPaymentDetails = in_array($document_type, ['Invoice', 'Quotation', 'Estimate', 'Purchase Order', 'Tax Invoice', 'Proforma Invoice']);

        $payment_link = '';
        if ($showPaymentDetails && ($settings['flw_active'] ?? false) && !empty($settings['flw_public_key'])) {
            // Tumia balance_due badala ya total_amount kwa link ya malipo
            $payment_url = "https://flutterwave.com/pay/" . $settings['flw_public_key'] . "?amount=" . $balance_due . "&currency=" . $currency_symbol . "&tx_ref=" . $invoice_number . "&email=" . $email_to_send . "&meta[invoice_id]=" . $invoice_id;
            $payment_link = "<a href='{$payment_url}' style='background-color: #4CAF50; color: white; padding: 10px 15px; text-align: center; text-decoration: none; display: inline-block; border-radius: 5px;'>Pay Online</a>";
        }

        $amount_line = '';
        if($document_type === 'Receipt') {
            $amount_line = "<p>Amount Paid: <strong>{$currency_symbol} " . number_format($total_amount, 2) . "</strong></p>";
        } else if ($document_type === 'Quotation' || $document_type === 'Estimate' || $document_type === 'Proforma Invoice') {
             $amount_line = "<p>Quoted Amount: <strong>{$currency_symbol} " . number_format($total_amount, 2) . "</strong></p>";
        } else if ($document_type === 'Invoice' || $document_type === 'Tax Invoice') {
             $amount_line = "<p>Amount Due: <strong>{$currency_symbol} " . number_format($total_amount, 2) . "</strong></p>";
        }
        // Delivery notes and others might not show an amount.

        // Tumia 'business_name' ya tenant kutoka $settings
        $business_name_from = htmlspecialchars($settings['business_name'] ?? 'Your Company');
        
        // Kama tenant anatumia 'custom' smtp, tumia 'smtp_from_name' yake
        if (!empty($settings['smtp_choice']) && $settings['smtp_choice'] === 'custom' && !empty($settings['smtp_from_name'])) {
            $business_name_from = htmlspecialchars($settings['smtp_from_name']);
        }
        
        $mail->Body = "
            <p>Hello {$name_to_send},</p> 
            <p>Please find your {$document_type} ({$invoice_number}) attached.</p>
            {$amount_line}
            " . ($showPaymentDetails && $due_date ? "<p>Due Date: {$due_date}</p>" : "") . "
            <p>{$payment_link}</p>
            <p>Thank you for your business!</p>
            <p>Best regards,<br>" . $business_name_from . "</p>
        ";
        $mail->addAttachment($pdf_path, $pdf_filename);
        $mail->send();

        echo json_encode(['status' => 'success', 'message' => "{$document_type} {$invoice_number} created and sent."]);

    } catch (Exception $e) {
         error_log("Invoice Email Error for Invoice ID {$invoice_id}: " . $mail->ErrorInfo);
        echo json_encode([
            'status' => 'warning',
            'message' => "{$document_type} {$invoice_number} was created, but sending email failed: {$mail->ErrorInfo}. Please send it manually via the list."
        ]);
    }
} else {
    // Hii ni kwa document (kama Delivery Note) isiyo na email ya contact
     echo json_encode([
        'status' => 'success',
        'message' => "{$document_type} {$invoice_number} created successfully. No email address found to send it automatically."
    ]);
}
?>
