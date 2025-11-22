<?php
// api/send_demand_notice.php
header('Content-Type: application/json');

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once 'db.php';
require_once 'invoice_templates.php';
require_once 'services/EmailService.php';
require_once __DIR__ . '/../vendor/autoload.php';

$userId = $_SESSION['user_id'];

$data = json_decode(file_get_contents('php://input'), true);
$invoiceId = $data['invoice_id'] ?? null;

if (!$invoiceId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invoice ID is required.']);
    exit;
}

try {
    // 1. Fetch all necessary data using the logged-in user's ID to scope queries
    $stmt_settings = $pdo->prepare("SELECT business_name, business_address, business_email, default_currency FROM users WHERE id = ?");
    $stmt_settings->execute([$userId]);
    $settings = $stmt_settings->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        throw new Exception("Could not find settings for the logged-in user.");
    }

    // Fetch Invoice and Customer data, ensuring it belongs to the logged-in user
    $stmt_invoice = $pdo->prepare("
        SELECT i.*, c.name as customer_name, c.email as customer_email 
        FROM invoices i 
        JOIN customers c ON i.customer_id = c.id 
        WHERE i.id = ? AND i.user_id = ?
    ");
    $stmt_invoice->execute([$invoiceId, $userId]);
    $invoice = $stmt_invoice->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Invoice not found or does not belong to you.']);
        exit;
    }
    
    // We already have customer name and email, but let's fetch the full customer record for the template
    $stmt_customer = $pdo->prepare("SELECT * FROM customers WHERE id = ? AND user_id = ?");
    $stmt_customer->execute([$invoice['customer_id'], $userId]);
    $customer = $stmt_customer->fetch(PDO::FETCH_ASSOC);

    // 2. Generate PDF
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor($settings['business_name']);
    $pdf->SetTitle('Demand Notice for Invoice ' . $invoice['invoice_number']);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();

    // Set font that supports a wide range of characters
    $pdf->SetFont('dejavusans', '', 10);

    $html = get_demand_notice_template_html($settings, $customer, $invoice);
    $pdf->writeHTML($html, true, false, true, false, '');

    $pdfDir = __DIR__ . '/../generated_pdfs/' . $tenantId;
    if (!is_dir($pdfDir)) {
        mkdir($pdfDir, 0777, true);
    }
    $pdfPath = $pdfDir . '/Demand_Notice_' . $invoice['invoice_number'] . '.pdf';
    $pdf->Output($pdfPath, 'F');

    // 3. Send Email
    $emailService = new EmailService($pdo, $tenantId);
    $subject = "URGENT: Demand Notice for Overdue Invoice #" . $invoice['invoice_number'];
    $body = "<p>Dear " . htmlspecialchars($customer['customer_name']) . ",</p>"
          . "<p>Please find attached a demand notice regarding your overdue invoice #" . htmlspecialchars($invoice['invoice_number']) . ".</p>"
          . "<p>We urge you to settle the outstanding amount at your earliest convenience to avoid further action.</p>"
          . "<p>Sincerely,<br>" . htmlspecialchars($settings['business_name']) . "</p>";

    $isSent = $emailService->sendEmail(
        $customer['customer_email'],
        $customer['customer_name'],
        $subject,
        $body,
        $pdfPath
    );

    if ($isSent) {
        echo json_encode(['success' => true, 'message' => 'Demand notice sent successfully.']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to send email. Please check your SMTP settings.']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}
?>
