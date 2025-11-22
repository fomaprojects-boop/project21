<?php
// api/send_payslips.php
require_once __DIR__ . '/../vendor/autoload.php';
require_once 'db.php';
require_once 'mailer_config.php';
session_start();

use TCPDF;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$batch_id = $data['batch_id'] ?? null;

if (empty($batch_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Batch ID is required.']);
    exit;
}

try {
    // 1. Hakikisha 'batch' ipo na imeshaidhishwa
    $stmt_batch = $pdo->prepare("SELECT * FROM payroll_batches WHERE id = ? AND status = 'approved'");
    $stmt_batch->execute([$batch_id]);
    $batch = $stmt_batch->fetch(PDO::FETCH_ASSOC);

    if (!$batch) {
        throw new Exception("Batch not found or not yet approved.");
    }

    // 2. Pata 'entries' ambazo hazijatumiwa 'payslip'
    $stmt_entries = $pdo->prepare("SELECT * FROM payroll_entries WHERE batch_id = ? AND payslip_sent = FALSE");
    $stmt_entries->execute([$batch_id]);
    $entries = $stmt_entries->fetchAll(PDO::FETCH_ASSOC);

    if (empty($entries)) {
        throw new Exception("All payslips for this batch have already been sent.");
    }

    $mail = getMailerInstance($pdo, $_SESSION['user_id']);
    $company_name = "Your Company Name"; // Unaweza kuitoa kwenye 'settings'

    foreach ($entries as $entry) {
        // 3. Tengeneza PDF kwa kila 'entry'
        $pdf = new TCPDF();
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);
        
        $html = "<h1>Payslip for {$entry['employee_name']}</h1>
                 <p><strong>Month/Year:</strong> {$batch['month']}/{$batch['year']}</p>
                 <hr>
                 <table border=\"1\" cellpadding=\"5\">
                     <tr><td>Basic Salary:</td><td align=\"right\">" . number_format($entry['basic_salary'], 2) . "</td></tr>
                     <tr><td>Allowances:</td><td align=\"right\">" . number_format($entry['allowances'], 2) . "</td></tr>
                     <tr><td><strong>Gross Salary:</strong></td><td align=\"right\"><strong>" . number_format($entry['basic_salary'] + $entry['allowances'], 2) . "</strong></td></tr>
                     <tr><td>Deductions:</td><td align=\"right\">(" . number_format($entry['deductions'], 2) . ")</td></tr>
                     <tr><td>Income Tax (PAYE):</td><td align=\"right\">(" . number_format($entry['income_tax'], 2) . ")</td></tr>
                     <tr><td><strong>Net Salary:</strong></td><td align=\"right\"><strong>" . number_format($entry['net_salary'], 2) . "</strong></td></tr>
                 </table>";
        
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf_content = $pdf->Output('', 'S'); // 'S' inarudisha kama 'string'

        // 4. Tuma barua pepe
        $mail->clearAddresses();
        $mail->addAddress($entry['employee_email'], $entry['employee_name']);
        $mail->Subject = "Your Payslip for {$batch['month']}/{$batch['year']}";
        $mail->Body = "Dear {$entry['employee_name']},\n\nPlease find your payslip for {$batch['month']}/{$batch['year']} attached.\n\nBest regards,\n{$company_name}";
        $mail->addStringAttachment($pdf_content, 'payslip.pdf', 'base64', 'application/pdf');
        
        if ($mail->send()) {
            // 5. Sasisha 'status' ya 'payslip_sent'
            $stmt_update = $pdo->prepare("UPDATE payroll_entries SET payslip_sent = TRUE WHERE id = ?");
            $stmt_update->execute([$entry['id']]);
        }
    }
    
    // (Optional) Sasisha 'batch status' iwe 'processed'
    $stmt_update_batch = $pdo->prepare("UPDATE payroll_batches SET status = 'processed' WHERE id = ?");
    $stmt_update_batch->execute([$batch_id]);

    echo json_encode(['status' => 'success', 'message' => 'Payslips sent successfully.']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
