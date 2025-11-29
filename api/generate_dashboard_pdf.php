<?php
// api/generate_dashboard_pdf.php
require_once 'config.php';
require_once 'db.php';
require_once '../vendor/autoload.php'; // Assuming composer autoload for TCPDF

session_start();

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access.");
}

$user_id = $_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'User';

// --- DATA FETCHING (Reusing Logic from get_dashboard_stats.php partially) ---
// We need basic stats for the PDF summary

function getMonthlyTaxData($pdo, $user_id, $type, $targetMonth, $targetYear) {
    if ($type === 'VAT') {
        $sql = "SELECT total_amount, tax_rate FROM invoices
                WHERE user_id = ? AND MONTH(issue_date) = ? AND YEAR(issue_date) = ?
                AND status = 'Paid' AND tax_rate > 0";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $targetMonth, $targetYear]);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = 0;
        foreach ($invoices as $inv) {
            $rate = floatval($inv['tax_rate']);
            $amt = floatval($inv['total_amount']);
            if ($rate > 0) $total += $amt - ($amt / (1 + ($rate / 100)));
        }
        return $total;
    } elseif ($type === 'WHT') {
        $sql = "SELECT SUM(amount * CASE WHEN service_type = 'Professional Service' THEN 0.05 WHEN service_type = 'Goods/Products' THEN 0.03 WHEN service_type = 'Rent' THEN 0.10 ELSE 0 END)
                FROM payout_requests WHERE MONTH(submitted_at) = ? AND YEAR(submitted_at) = ? AND (status = 'Approved' OR status = 'Paid' OR status = 'Processed')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$targetMonth, $targetYear]);
        return $stmt->fetchColumn() ?: 0;
    }
    return 0;
}

$current_month = (int)date('m');
$current_year = (int)date('Y');

// 1. Revenue
$stmt = $pdo->prepare("SELECT SUM(amount_paid) FROM invoices WHERE user_id = ? AND status = 'Paid' AND MONTH(issue_date) = ? AND YEAR(issue_date) = ?");
$stmt->execute([$user_id, $current_month, $current_year]);
$total_revenue = $stmt->fetchColumn() ?: 0;

// 2. Expenses
$expenses_total = 0;
$stmt = $pdo->prepare("SELECT SUM(amount) FROM direct_expenses WHERE user_id = ? AND (status = 'Paid' OR status = 'Approved' OR status = 'Posted to GL') AND MONTH(date) = ? AND YEAR(date) = ?");
$stmt->execute([$user_id, $current_month, $current_year]);
$expenses_total += ($stmt->fetchColumn() ?: 0);

$stmt = $pdo->prepare("SELECT SUM(amount) FROM payout_requests WHERE (status = 'Approved' OR status = 'Paid' OR status = 'Processed') AND service_type NOT LIKE '%Asset%' AND MONTH(submitted_at) = ? AND YEAR(submitted_at) = ?");
$stmt->execute([$current_month, $current_year]);
$expenses_total += ($stmt->fetchColumn() ?: 0);

// 3. Net Profit
$net_profit = $total_revenue - $expenses_total;

// 4. Taxes (Current Status)
// Re-calculate basic accumulating for report
$vat_amount = getMonthlyTaxData($pdo, $user_id, 'VAT', $current_month, $current_year);
$wht_amount = getMonthlyTaxData($pdo, $user_id, 'WHT', $current_month, $current_year);
$stmt = $pdo->prepare("SELECT SUM(amount * 0.01) FROM payout_requests WHERE service_type = 'Rent' AND MONTH(submitted_at) = ? AND YEAR(submitted_at) = ? AND (status = 'Approved' OR status = 'Paid' OR status = 'Processed')");
$stmt->execute([$current_month, $current_year]);
$stamp_duty = $stmt->fetchColumn() ?: 0;

// 5. Recent Transactions
$activity_sql = "
    (SELECT 'Invoice' as type, invoice_number as reference, total_amount as amount, created_at
     FROM invoices WHERE user_id = ?)
    UNION ALL
    (SELECT 'Expense' as type, expense_type as reference, amount, created_at
     FROM direct_expenses WHERE user_id = ?)
    ORDER BY created_at DESC LIMIT 5
";
$stmt = $pdo->prepare($activity_sql);
$stmt->execute([$user_id, $user_id]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);


// --- PDF GENERATION ---
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('ChatMe System');
$pdf->SetAuthor($userName);
$pdf->SetTitle('Monthly Dashboard Report');

// Remove default header/footer
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// Set margins
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(TRUE, 15);

// Add a page
$pdf->AddPage();

// -- CONTENT --

// 1. Header
$pdf->SetFont('dejavusans', 'B', 20);
$pdf->SetTextColor(55, 65, 81); // Dark Gray
$pdf->Cell(0, 10, 'Monthly Performance Report', 0, 1, 'L');
$pdf->SetFont('dejavusans', '', 10);
$pdf->SetTextColor(107, 114, 128); // Gray
$pdf->Cell(0, 6, 'Generated on ' . date('F j, Y H:i') . ' by ' . $userName, 0, 1, 'L');
$pdf->Ln(5);
$pdf->Cell(0, 0, '', 'T'); // Divider line
$pdf->Ln(10);

// 2. Financial Summary Cards (Simulated with Table)
$pdf->SetFont('dejavusans', 'B', 14);
$pdf->SetTextColor(55, 65, 81);
$pdf->Cell(0, 10, 'Financial Overview (' . date('F Y') . ')', 0, 1);
$pdf->Ln(2);

// Colors for table
$bg_gray = '#F3F4F6';
$text_violet = '#7C3AED';
$text_green = '#059669';
$text_red = '#DC2626';

$tbl = <<<EOD
<table cellspacing="10" cellpadding="10" border="0">
    <tr>
        <td width="33%" bgcolor="#F9FAFB" style="border:1px solid #E5E7EB; border-radius: 10px;">
            <span style="color:#6B7280; font-size:9pt;">Total Revenue</span><br>
            <span style="color:#7C3AED; font-size:16pt; font-weight:bold;">TZS {$total_revenue}</span>
        </td>
        <td width="33%" bgcolor="#F9FAFB" style="border:1px solid #E5E7EB;">
            <span style="color:#6B7280; font-size:9pt;">Total Expenses</span><br>
            <span style="color:#DC2626; font-size:16pt; font-weight:bold;">TZS {$expenses_total}</span>
        </td>
        <td width="33%" bgcolor="#F9FAFB" style="border:1px solid #E5E7EB;">
            <span style="color:#6B7280; font-size:9pt;">Net Profit</span><br>
            <span style="color:#059669; font-size:16pt; font-weight:bold;">TZS {$net_profit}</span>
        </td>
    </tr>
</table>
EOD;

$pdf->writeHTML($tbl, true, false, false, false, '');
$pdf->Ln(5);

// 3. Tax Breakdown
$pdf->SetFont('dejavusans', 'B', 12);
$pdf->Cell(0, 10, 'Tax Liabilities (Accruing)', 0, 1);
$pdf->SetFont('dejavusans', '', 10);

$tax_tbl = <<<EOD
<table cellspacing="0" cellpadding="6" border="1" bordercolor="#E5E7EB">
    <tr bgcolor="#F3F4F6">
        <th width="40%">Tax Type</th>
        <th width="30%">Status</th>
        <th width="30%" align="right">Amount</th>
    </tr>
    <tr>
        <td>VAT (18%)</td>
        <td>Accruing</td>
        <td align="right">TZS {$vat_amount}</td>
    </tr>
    <tr>
        <td>Withholding Tax</td>
        <td>Accruing</td>
        <td align="right">TZS {$wht_amount}</td>
    </tr>
    <tr>
        <td>Stamp Duty (Rent)</td>
        <td>Liability</td>
        <td align="right">TZS {$stamp_duty}</td>
    </tr>
</table>
EOD;

$pdf->writeHTML($tax_tbl, true, false, false, false, '');
$pdf->Ln(10);

// 4. Recent Transactions
$pdf->SetFont('dejavusans', 'B', 12);
$pdf->Cell(0, 10, 'Recent Activity (Top 5)', 0, 1);
$pdf->SetFont('dejavusans', '', 10);

$trans_rows = '';
foreach ($transactions as $t) {
    $date = date('d M Y', strtotime($t['created_at']));
    $amount_fmt = number_format($t['amount'], 2);
    $color = $t['type'] == 'Invoice' ? '#059669' : '#DC2626'; // Green vs Red text for amount

    $trans_rows .= <<<EOD
    <tr>
        <td>{$date}</td>
        <td>{$t['type']}</td>
        <td>{$t['reference']}</td>
        <td align="right" style="color:{$color}; font-weight:bold;">TZS {$amount_fmt}</td>
    </tr>
EOD;
}

$trans_tbl = <<<EOD
<table cellspacing="0" cellpadding="6" border="1" bordercolor="#E5E7EB">
    <tr bgcolor="#F3F4F6">
        <th width="25%">Date</th>
        <th width="20%">Type</th>
        <th width="30%">Reference</th>
        <th width="25%" align="right">Amount</th>
    </tr>
    {$trans_rows}
</table>
EOD;

$pdf->writeHTML($trans_tbl, true, false, false, false, '');

// Footer Note
$pdf->Ln(20);
$pdf->SetFont('dejavusans', 'I', 8);
$pdf->SetTextColor(156, 163, 175);
$pdf->Cell(0, 10, 'This report was automatically generated by the system.', 0, 1, 'C');

// Output
$pdf->Output('Dashboard_Report_' . date('Y_m_d') . '.pdf', 'I');
?>
