<?php
// api/generate_statement_pdf.php
session_start();

require_once 'db.php';
require_once 'financial_helpers.php'; 
require_once __DIR__ . '/../vendor/autoload.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['customer_id'])) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Unauthorized access or required parameter (customer_id) is missing.';
    exit();
}

$userId = $_SESSION['user_id'];
$customerId = (int)$_GET['customer_id'];
$period = $_GET['period'] ?? 'all';

try {
    // RBAC check
    $stmt_role = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt_role->execute([$userId]);
    $userRole = strtolower($stmt_role->fetchColumn());

    $allowedRoles = ['admin', 'accountant'];
    if (!in_array($userRole, $allowedRoles)) {
        http_response_code(403);
        header('Content-Type: text/plain');
        echo 'Access Denied: You do not have permission to perform this action.';
        exit();
    }

    // --- START OF CUSTOM PDF GENERATION ---

    if (!class_exists('TCPDF')) {
        throw new Exception("TCPDF class not found.");
    }

    // 1. Fetch Settings
    $stmt_settings = $pdo->prepare("SELECT business_name, business_email, business_address, business_stamp_url, profile_picture_url, default_currency, tin_number, vat_number FROM settings WHERE id = 1");
    $stmt_settings->execute();
    $general_settings = $stmt_settings->fetch(PDO::FETCH_ASSOC);
    if (!$general_settings) {
        $general_settings = [
            'business_name' => 'My Company',
            'default_currency' => 'TZS'
        ];
    }

    $stmt_user_settings = $pdo->prepare("SELECT tin_number, vrn_number FROM users WHERE id = ?");
    $stmt_user_settings->execute([$userId]);
    $user_settings = $stmt_user_settings->fetch(PDO::FETCH_ASSOC);

    $settings = array_merge($general_settings, $user_settings ?: []);

    // 2. Prepare Images
    $baseUploadPath = dirname(__DIR__) . '/uploads/'; 
    
    // Logo
    $logoUrl = '';
    if (!empty($settings['profile_picture_url'])) {
        $logoFilename = basename($settings['profile_picture_url']);
        if (file_exists($baseUploadPath . $logoFilename)) {
            $logoUrl = $baseUploadPath . $logoFilename;
        } else {
            $logoUrl = 'https://app.chatme.co.tz/uploads/' . $logoFilename;
        }
    }

    // Stamp
    $stampUrl = '';
    if (!empty($settings['business_stamp_url'])) {
         $stampFilename = basename($settings['business_stamp_url']);
         if (file_exists($baseUploadPath . $stampFilename)) {
             $stampUrl = $baseUploadPath . $stampFilename;
         } else {
             $stampUrl = 'https://app.chatme.co.tz/uploads/' . $stampFilename;
         }
    }

    // 3. Fetch Customer Info
    $stmt_customer = $pdo->prepare("SELECT name, email, phone FROM customers WHERE id = ?");
    $stmt_customer->execute([$customerId]);
    $customer = $stmt_customer->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        throw new Exception("Customer not found.");
    }

    // 4. Determine Date Range
    $dateCondition = "";
    $dateParams = [];
    $endDate = new DateTime();
    $startDate = null;

    switch ($period) {
        case 'day': $dateCondition = " AND i.issue_date = CURDATE()"; $startDate = new DateTime(); break;
        case 'week': $startDate = new DateTime('monday this week'); $dateCondition = " AND i.issue_date >= ?"; $dateParams[] = $startDate->format('Y-m-d'); break;
        case 'month': $startDate = new DateTime('first day of this month'); $dateCondition = " AND i.issue_date >= ?"; $dateParams[] = $startDate->format('Y-m-d'); break;
        case 'year': $startDate = new DateTime('first day of January this year'); $dateCondition = " AND i.issue_date >= ?"; $dateParams[] = $startDate->format('Y-m-d'); break;
        default:
            $stmtDate = $pdo->prepare("SELECT MIN(issue_date) FROM invoices WHERE customer_id = ?");
            $stmtDate->execute([$customerId]);
            $startDateStr = $stmtDate->fetchColumn();
            if ($startDateStr) { $startDate = new DateTime($startDateStr); }
            break;
    }
    $dateRange = $startDate ? $startDate->format("M d, Y") . " - " . $endDate->format("M d, Y") : "All Time";

    // 5. Fetch Documents
    $docParams = array_merge([$customerId], $dateParams);
    $stmtDocs = $pdo->prepare("
        SELECT 
            i.invoice_number, 
            i.issue_date, 
            i.due_date, 
            i.total_amount, 
            i.amount_paid, 
            i.tax_amount,
            (i.total_amount - i.amount_paid) as balance_due,
            i.status,
            i.document_type
        FROM invoices i 
        WHERE i.customer_id = ? $dateCondition 
        ORDER BY i.issue_date ASC, i.id ASC
    "); 
    
    $stmtDocs->execute($docParams);
    $documents = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);

    // Calculate Summaries
    $total_billed = 0;
    $total_paid = 0;
    $total_tax = 0;
    $total_subtotal = 0;

    foreach ($documents as $doc) {
        if ($doc['document_type'] === 'Receipt') {
            $total_paid += $doc['total_amount'];
            continue; 
        }

        $total_billed += $doc['total_amount'];
        $total_paid += $doc['amount_paid'];
        $total_tax += $doc['tax_amount'];
        $total_subtotal += ($doc['total_amount'] - $doc['tax_amount']);
    }
    $total_due = $total_billed - $total_paid;

    // 6. Generate PDF
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor($settings['business_name'] ?? 'System');
    $pdf->SetTitle('Statement - ' . $customer['name']);
    
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 15);
    $pdf->AddPage();
    
    // --- CONFIG ---
    $fontFamily = 'helvetica'; 
    $brandColor = '#2c3e50'; 
    $currency = htmlspecialchars($settings['default_currency'] ?? 'TZS');

    // Prepare Header Data
    $businessName = strtoupper($settings['business_name']);
    $tinNumber = $settings['tin_number'] ?? '';
    $vatNumber = !empty($settings['vrn_number']) ? $settings['vrn_number'] : ($settings['vat_number'] ?? '');
    $email = $settings['business_email'] ?? '';
    $addressFormatted = nl2br(htmlspecialchars($settings['business_address'] ?? ''));

    // Logo
    $logoImgTag = '';
    if ($logoUrl) {
        $logoImgTag = '<img src="'.$logoUrl.'" height="50" />';
    }

    // Tax Info
    $taxInfoHtml = '';
    if ($tinNumber) $taxInfoHtml .= 'TIN: ' . $tinNumber . '<br>';
    if ($vatNumber) $taxInfoHtml .= 'VRN: ' . $vatNumber;

    // --- 1. HEADER ---
    $headerHtml = <<<EOD
<table border="0" cellpadding="0" cellspacing="0" width="100%">
    <tr>
        <td width="50%" align="left" valign="top">
            {$logoImgTag}
        </td>
        <td width="50%" align="right" style="color: #333;">
            <span style="font-size: 14pt; font-weight: bold; color: {$brandColor};">{$businessName}</span><br>
            <span style="font-size: 9pt; color: #555;">{$addressFormatted}</span><br>
            <span style="font-size: 9pt; color: #555;">{$email}</span><br>
            <span style="font-size: 9pt; font-weight: bold; color: #555;">{$taxInfoHtml}</span>
        </td>
    </tr>
</table>
<hr style="height: 1px; border: 0; color: #ddd; background-color: #ddd;">
EOD;

    $pdf->SetFont($fontFamily, '', 10);
    $pdf->writeHTML($headerHtml, true, false, false, false, '');

    // --- 2. CUSTOMER INFO ---
    $customerNameUpper = strtoupper($customer['name']);
    
    $customerInfoHtml = <<<EOD
<div style="font-size: 12pt; font-weight: bold; color: {$brandColor}; padding-bottom: 5px;">
    Customer Statement for {$customerNameUpper}
</div>
<div style="font-size: 10pt; line-height: 1.4;">
    {$customer['email']}<br>
    {$customer['phone']}
</div>
<div style="font-size: 10pt; color: #666; padding-top: 5px;">
    <strong>Period:</strong> {$dateRange}
</div>
<br><br>
EOD;
    $pdf->writeHTML($customerInfoHtml, true, false, false, false, '');

    // --- 3. TABLE ---
    $tableHeader = <<<EOD
<tr style="background-color: #e9ecef; font-weight: bold;">
    <th width="15%" style="border: 1px solid #cccccc; padding: 6px;">Number</th>
    <th width="15%" style="border: 1px solid #cccccc; padding: 6px;">Date</th>
    <th width="17.5%" align="right" style="border: 1px solid #cccccc; padding: 6px;">Subtotal</th>
    <th width="15%" align="right" style="border: 1px solid #cccccc; padding: 6px;">Tax</th>
    <th width="17.5%" align="right" style="border: 1px solid #cccccc; padding: 6px;">Paid Amount</th>
    <th width="20%" align="right" style="border: 1px solid #cccccc; padding: 6px;">Total</th>
</tr>
EOD;

    $tableRows = '';
    foreach ($documents as $doc) {
        if ($doc['document_type'] === 'Receipt') continue;

        $subtotal = $doc['total_amount'] - $doc['tax_amount'];
        $subtotalFmt = number_format($subtotal, 2);
        $taxFmt = number_format($doc['tax_amount'], 2);
        $paidFmt = number_format($doc['amount_paid'], 2);
        $totalFmt = number_format($doc['total_amount'], 2);
        
        $dateObj = new DateTime($doc['issue_date']);
        $dateFmt = $dateObj->format('d/m/Y');

        $tableRows .= <<<EOD
<tr>
    <td style="border: 1px solid #cccccc; padding: 6px;">{$doc['invoice_number']}</td>
    <td style="border: 1px solid #cccccc; padding: 6px;">{$dateFmt}</td>
    <td align="right" style="border: 1px solid #cccccc; padding: 6px;">{$subtotalFmt}</td>
    <td align="right" style="border: 1px solid #cccccc; padding: 6px;">{$taxFmt}</td>
    <td align="right" style="border: 1px solid #cccccc; padding: 6px;">{$paidFmt}</td>
    <td align="right" style="border: 1px solid #cccccc; padding: 6px;">{$currency} {$totalFmt}</td>
</tr>
EOD;
    }

    if (empty($documents)) {
        $tableRows = '<tr><td colspan="6" align="center" style="border: 1px solid #cccccc; padding: 10px;">No invoices found for this period.</td></tr>';
    }

    $tableHtml = <<<EOD
<table cellspacing="0" cellpadding="4" border="0">
    {$tableHeader}
    {$tableRows}
</table>
<br>
EOD;
    $pdf->writeHTML($tableHtml, true, false, false, false, '');

    // --- 4. SUMMARY ---
    $totalBilledFmt = number_format($total_billed, 2) . ' ' . $currency;
    $totalPaidFmt = number_format($total_paid, 2) . ' ' . $currency;
    $totalDueFmt = number_format($total_due, 2) . ' ' . $currency;
    
    $summaryHtml = <<<EOD
<table border="0" cellpadding="5" cellspacing="0" width="100%">
    <tr>
        <td width="60%"></td>
        <td width="20%" style="font-weight: bold;">Total Billed</td>
        <td width="20%" align="right" style="font-weight: bold;">{$totalBilledFmt}</td>
    </tr>
    <tr>
        <td width="60%"></td> 
        <td width="20%" style="font-weight: bold; color: #27ae60;">Total Paid</td>
        <td width="20%" align="right" style="font-weight: bold; color: #27ae60;">{$totalPaidFmt}</td>
    </tr>
    <tr>
        <td width="60%"></td> 
        <td width="20%" style="font-weight: bold; font-size: 11pt; color: {$brandColor}; border-top: 1px solid #ccc;">Balance Due</td>
        <td width="20%" align="right" style="font-weight: bold; font-size: 11pt; color: {$brandColor}; border-top: 1px solid #ccc;">{$totalDueFmt}</td>
    </tr>
</table>
EOD;
    $pdf->writeHTML($summaryHtml, true, false, false, false, '');
    
    // --- 5. FOOTER / STAMP (THE SWAG EDITION) ---
    if ($stampUrl) {
        $pdf->Ln(10); // Good spacing from content
        
        // MODERN CARD DESIGN:
        // - Full width table
        // - Light Blue-ish Gray Background (#f4f6f7)
        // - Left Accent Border (Brand Color)
        // - Signature + Official Title
        
        $stampHtml = <<<EOD
<table border="0" cellpadding="0" cellspacing="0" width="100%">
    <tr>
        <td>
            <table border="0" cellpadding="10" cellspacing="0" width="100%" style="background-color: #f4f6f7; border-left: 5px solid {$brandColor};">
                <tr>
                    <td width="25%" align="center" valign="middle" style="border-right: 1px solid #e0e0e0;">
                         <img src="{$stampUrl}" height="90" />
                    </td>
                    <td width="75%" valign="middle" style="padding-left: 15px;">
                        <span style="color: #7f8c8d; font-size: 8pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1px;">Authorized Signatory</span><br>
                        <span style="color: #2c3e50; font-size: 10pt; font-weight: bold;">{$businessName}</span><br>
                        <span style="color: #95a5a6; font-size: 8pt;">This document is valid without an official seal.</span>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
EOD;
        $pdf->writeHTML($stampHtml, true, false, false, false, '');
    }

    // Output
    $safe_customer_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $customer['name']);
    $pdf_filename = 'Statement_' . $safe_customer_name . '_' . date('Ymd_His') . '.pdf';

    $pdf->Output($pdf_filename, 'I');

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    error_log("Failed to generate PDF statement: " . $e->getMessage());
    echo 'An unexpected error occurred: ' . $e->getMessage();
}
?>