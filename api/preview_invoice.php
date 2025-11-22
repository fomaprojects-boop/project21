<?php
// api/preview_invoice.php
session_start();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice_templates.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo "Unauthorized";
    exit();
}

$invoice_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$invoice_id) {
    http_response_code(400);
    echo "No invoice ID provided.";
    exit();
}

try {
    // 1. Fetch all necessary data from the database
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        throw new Exception("Invoice not found.");
    }

    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$invoice['customer_id']]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    $contact = [];
    if ($invoice['contact_id']) {
        $stmt = $pdo->prepare("SELECT * FROM contacts WHERE id = ?");
        $stmt->execute([$invoice['contact_id']]);
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
    $stmt->execute([$invoice_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("SELECT * FROM settings WHERE id = 1"); // Assuming tenant settings are in a different table or filtered by tenant_id
    $stmt->execute();
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    // Define the correct base URL for uploads, ensuring it ends with a slash
    $base_url = 'https://mteja.fomaentertainment.com/uploads/';

    // Reconstruct the profile picture URL to guarantee it's correct
    if (!empty($settings['profile_picture_url'])) {
        $filename = basename($settings['profile_picture_url']);
        $settings['profile_picture_url'] = $base_url . $filename;
    }

    // Reconstruct the business stamp URL to guarantee it's correct
    if (!empty($settings['business_stamp_url'])) {
        $filename = basename($settings['business_stamp_url']);
        $settings['business_stamp_url'] = $base_url . $filename;
    }

    // If this is a converted receipt, get the original invoice number
    if ($invoice['document_type'] === 'Receipt' && !empty($invoice['converted_from_id'])) {
        $stmt_orig = $pdo->prepare("SELECT invoice_number FROM invoices WHERE id = ?");
        $stmt_orig->execute([$invoice['converted_from_id']]);
        $original_invoice_number = $stmt_orig->fetchColumn();
        if ($original_invoice_number) {
            $invoice['original_invoice_number'] = $original_invoice_number;
        }
    }

    // 2. Select the correct template function based on document_type
    $pdf_html = '';
    switch ($invoice['document_type']) {
        case 'Invoice':
        case 'Tax Invoice':
            $pdf_html = get_tax_invoice_template_html($settings, $customer, $contact, $invoice, $items);
            break;
        case 'Receipt':
            $pdf_html = get_receipt_template_html($settings, $customer, $contact, $invoice, $items);
            break;
        case 'Quotation':
        case 'Estimate':
            $pdf_html = get_quotation_template_html($settings, $customer, $contact, $invoice, $items);
            break;
        case 'Proforma Invoice':
            $pdf_html = get_proforma_invoice_template_html($settings, $customer, $contact, $invoice, $items);
            break;
        case 'Delivery Note':
            $pdf_html = get_delivery_note_template_html($settings, $customer, $contact, $invoice, $items);
            break;
        case 'Purchase Order':
            $pdf_html = get_purchase_order_template_html($settings, $customer, $contact, $invoice, $items);
            break;
        default:
            $pdf_html = get_default_template_html($settings, $customer, $contact, $invoice, $items);
            break;
    }

    // 3. Generate and output the PDF
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetAutoPageBreak(TRUE, 10);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 10);
    $pdf->writeHTML($pdf_html, true, false, true, false, '');
    
    $pdf_filename = strtolower(str_replace(' ', '_', $invoice['document_type'])) . '_' . str_replace('-', '_', $invoice['invoice_number']) . '.pdf';
    $pdf->Output($pdf_filename, 'I'); // 'I' for inline display

} catch (Exception $e) {
    http_response_code(500);
    echo 'Error generating PDF: ' . $e->getMessage();
}
?>