<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once 'config.php';
require_once 'db.php';
require_once 'invoice_templates.php'; 

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die('Please log in to generate reports.');
}

$userId = $_SESSION['user_id'];
$statusFilter = $_GET['status'] ?? 'All';
$periodFilter = $_GET['period'] ?? 'all';
$docTypesFilter = $_GET['doc_types'] ?? [];

// Start building the query and parameters using positional placeholders
$sql = "SELECT i.invoice_number, i.issue_date, i.total_amount, i.amount_paid, i.status, c.name as customer_name, i.document_type
        FROM invoices i
        JOIN customers c ON i.customer_id = c.id
        WHERE i.user_id = ? AND i.status <> 'Converted'";
$params = [$userId];

if ($statusFilter !== 'All') {
    if ($statusFilter === 'Overdue') {
        $sql .= " AND i.status <> 'Paid' AND i.due_date < CURDATE()";
    } else {
        $sql .= " AND i.status = ?";
        $params[] = $statusFilter;
    }
}

switch ($periodFilter) {
    case 'day': $sql .= " AND i.issue_date >= CURDATE()"; break;
    case 'week': $sql .= " AND i.issue_date >= DATE_SUB(CURDATE(), INTERVAL 1 WEEK)"; break;
    case 'month': $sql .= " AND i.issue_date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)"; break;
    case 'year': $sql .= " AND i.issue_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)"; break;
}

// Add document type filter
if (!empty($docTypesFilter) && is_array($docTypesFilter)) {
    $placeholders = implode(',', array_fill(0, count($docTypesFilter), '?'));
    $sql .= " AND i.document_type IN ($placeholders)";
    $params = array_merge($params, $docTypesFilter);
}


$sql .= " ORDER BY i.issue_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_billed = 0;
$total_paid = 0;
foreach ($invoices as $invoice) {
    $total_billed += $invoice['total_amount'];
    $total_paid += $invoice['amount_paid'];
}
$balance_due = $total_billed - $total_paid;

// --- Improved Filter Display Logic ---
$today = new DateTime();
$period_string = '';
switch ($periodFilter) {
    case 'day':
        $period_string = $today->format('F d, Y');
        break;
    case 'week':
        $start_date = (clone $today)->modify('-1 week');
        $period_string = $start_date->format('M d, Y') . ' - ' . $today->format('M d, Y');
        break;
    case 'month':
        $start_date = (clone $today)->modify('-1 month');
        $period_string = $start_date->format('M d, Y') . ' - ' . $today->format('M d, Y');
        break;
    case 'year':
        $start_date = (clone $today)->modify('-1 year');
        $period_string = $start_date->format('M d, Y') . ' - ' . $today->format('M d, Y');
        break;
    default:
        $period_string = 'All Time';
        break;
}

// Don't escape HTML for status, as the template handles it correctly
$status_html = $statusFilter;
if ($statusFilter === 'Paid') {
    $status_html = '<span style="color: #00897b;">' . htmlspecialchars($statusFilter) . '</span>';
}


$settings = $pdo->query("SELECT business_name, profile_picture_url, business_email, default_currency FROM settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: ['business_name' => 'Your Company', 'default_currency' => 'TZS'];
$filter_details = ['status' => $status_html, 'period' => $period_string];
$summary_totals = ['total_billed' => $total_billed, 'total_paid' => $total_paid, 'balance_due' => $balance_due];

$pdf_html = get_classic_statement_template_html($settings, $invoices, $filter_details, $summary_totals);

$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
$pdf->AddPage();
$pdf->writeHTML($pdf_html, true, false, true, false, '');

$pdf->Output('Statement_Report.pdf', 'I');
