<?php
// api/convert_document.php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice_templates.php'; 

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$source_id = $data['from_id'] ?? null;
$target_type = $data['to_type'] ?? null;

if (!$source_id || !$target_type) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Source ID and target type are required.']);
    exit();
}

try {
    $pdo->beginTransaction();

    // Get the source document
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND user_id = ?");
    $stmt->execute([$source_id, $user_id]);
    $source_doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$source_doc) {
        throw new Exception("Source document not found.");
    }

    // Get items from the source document
    $stmt_items = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
    $stmt_items->execute([$source_id]);
    $source_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    // Create a new document
    $stmt_insert = $pdo->prepare(
        "INSERT INTO invoices (customer_id, contact_id, user_id, invoice_number, document_type, converted_from_id, status, issue_date, due_date, subtotal, tax_rate, tax_amount, total_amount, amount_paid, balance_due, notes, payment_method_info)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    // Generate a new invoice number for the new document
    $stmt_num = $pdo->query("SELECT MAX(id) AS max_id FROM invoices");
    $last_id = $stmt_num->fetchColumn();
    $next_id = ($last_id ?: 0) + 1;
    $new_invoice_number = strtoupper(substr($target_type, 0, 3)) . "-" . date('Y') . "-" . str_pad($next_id, 4, '0', STR_PAD_LEFT);

    // Custom logic for receipts
    $balance_due = $source_doc['total_amount'];
    $amount_paid = 0;
    if ($target_type === 'Receipt') {
        $balance_due = 0;
        $amount_paid = $source_doc['total_amount'];
    }


    $stmt_insert->execute([
        $source_doc['customer_id'],
        $source_doc['contact_id'],
        $user_id,
        $new_invoice_number,
        $target_type,
        $source_id,
        'Draft', // New documents start as Draft
        date('Y-m-d'), // Today's date
        $source_doc['due_date'],
        $source_doc['subtotal'],
        $source_doc['tax_rate'],
        $source_doc['tax_amount'],
        $source_doc['total_amount'],
        $amount_paid, // Use calculated amount paid
        $balance_due, // Use calculated balance due
        $source_doc['notes'],
        $source_doc['payment_method_info']
    ]);

    $new_invoice_id = $pdo->lastInsertId();

    // Copy items to the new document
    $stmt_item_insert = $pdo->prepare(
        "INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?)"
    );

    foreach ($source_items as $item) {
        $stmt_item_insert->execute([
            $new_invoice_id,
            $item['description'],
            $item['quantity'],
            $item['unit_price'],
            $item['total']
        ]);
    }

    // --- PDF GENERATION START ---

    // 1. Get all data needed for the PDF
    $settings = $pdo->query("SELECT * FROM settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    if (!$settings) {
        $settings = ['business_name' => 'Your Company', 'default_currency' => 'TZS'];
    }

    $stmt_cust = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt_cust->execute([$source_doc['customer_id']]);
    $customer_main_info = $stmt_cust->fetch(PDO::FETCH_ASSOC);
    if (!$customer_main_info) {
        throw new Exception("Customer details not found for PDF generation.");
    }
    
    $contact_info = [];
    if ($source_doc['contact_id']) {
        $stmt_contact = $pdo->prepare("SELECT * FROM contacts WHERE id = ? AND customer_id = ?");
        $stmt_contact->execute([$source_doc['contact_id'], $source_doc['customer_id']]);
        $contact_info = $stmt_contact->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    // Fetch the full record for the *newly created* document
    $stmt_new_doc = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
    $stmt_new_doc->execute([$new_invoice_id]);
    $invoice_data_db = $stmt_new_doc->fetch(PDO::FETCH_ASSOC);

    // Fetch the items for the *newly created* document
    $stmt_new_items = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
    $stmt_new_items->execute([$new_invoice_id]);
    $items_data_db = $stmt_new_items->fetchAll(PDO::FETCH_ASSOC);
    
    // If this is a converted receipt, get the original invoice number to display
    if ($target_type === 'Receipt' && !empty($invoice_data_db['converted_from_id'])) {
        $stmt_orig = $pdo->prepare("SELECT invoice_number FROM invoices WHERE id = ?");
        $stmt_orig->execute([$invoice_data_db['converted_from_id']]);
        $original_invoice_number = $stmt_orig->fetchColumn();
        if ($original_invoice_number) {
            $invoice_data_db['original_invoice_number'] = $original_invoice_number;
        }
    }

    // 2. Select the correct HTML template
    $pdf_html = '';
    switch ($target_type) {
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
            $pdf_html = get_default_template_html($settings, $customer_main_info, $contact_info, $invoice_data_db, $items_data_db);
            break;
    }
    
    // 3. Generate and save the PDF file
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetAutoPageBreak(TRUE, 10);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 10);
    $pdf->writeHTML($pdf_html, true, false, true, false, '');

    $upload_dir = __DIR__ . '/../uploads/customer_invoices/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0775, true);

    $pdf_filename = strtolower(str_replace(' ', '_', $target_type)) . '_' . str_replace('-', '_', $new_invoice_number) . '_' . time() . '.pdf';
    $pdf_path = $upload_dir . $pdf_filename;
    $pdf->Output($pdf_path, 'F');
    $pdf_url = 'uploads/customer_invoices/' . $pdf_filename;

    // 4. Update the new invoice with the PDF URL and a final status
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
    $finalStatus = $finalStatusMap[$target_type] ?? 'Sent';

    $stmt_pdf = $pdo->prepare("UPDATE invoices SET pdf_url = ?, status = ? WHERE id = ?");
    $stmt_pdf->execute([$pdf_url, $finalStatus, $new_invoice_id]);

    // --- PDF GENERATION END ---

    // 5. Update the source document status to 'Converted'
    $stmt_update_source = $pdo->prepare("UPDATE invoices SET status = 'Converted' WHERE id = ?");
    $stmt_update_source->execute([$source_id]);

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => "Document converted to {$target_type} successfully.", 'new_invoice_id' => $new_invoice_id]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
