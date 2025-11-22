<?php
session_start();
require_once __DIR__ . '/../vendor/autoload.php';
require_once 'db.php';
require_once 'financial_helpers.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die('Please log in to generate reports.');
}

$year = $_GET['year'] ?? date('Y');

// Fetch all financial data in a structured way to prevent recursion
$all_financial_data = get_complete_financial_data($year);
$summary_data = $all_financial_data['summary_data'];
$balance_sheet_data = $all_financial_data['balance_sheet_data'];
$cash_flow_data = $all_financial_data['cash_flow_data'];
$settings = get_settings();
$currency = htmlspecialchars($settings['default_currency'] ?? 'TZS');

// Create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor($settings['business_name'] ?? 'System');
$pdf->SetTitle('Financial Statement for ' . $year);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(true);

// Add a page
$pdf->AddPage();

// Set some content to display
$html = '<h1>Financial Statement for the Year ' . $year . '</h1>';

// Financial Summary Table
$html .= '<h2>Financial Summary</h2>';
$html .= '<table border="1" cellpadding="4">
            <tr>
                <td></td>
                <td><strong>' . $year . ' (' . $currency . ')</strong></td>
                <td><strong>' . ($year - 1) . ' (' . $currency . ')</strong></td>
            </tr>
            <tr>
                <td><strong>Total Assets</strong></td>
                <td>' . format_accounting_number($balance_sheet_data[$year]['total_assets']) . '</td>
                <td>' . format_accounting_number($balance_sheet_data[$year - 1]['total_assets']) . '</td>
            </tr>
            <tr>
                <td><strong>Total Liabilities</strong></td>
                <td>' . format_accounting_number($balance_sheet_data[$year]['total_liabilities_and_equity'] - $balance_sheet_data[$year]['equity']) . '</td>
                <td>' . format_accounting_number($balance_sheet_data[$year - 1]['total_liabilities_and_equity'] - $balance_sheet_data[$year - 1]['equity']) . '</td>
            </tr>
            <tr>
                <td><strong>Total Equity</strong></td>
                <td>' . format_accounting_number($balance_sheet_data[$year]['equity']) . '</td>
                <td>' . format_accounting_number($balance_sheet_data[$year - 1]['equity']) . '</td>
            </tr>
            <tr>
                <td><strong>Gross Profit</strong></td>
                <td>' . format_accounting_number($summary_data[$year]['gross_profit']) . '</td>
                <td>' . format_accounting_number($summary_data[$year - 1]['gross_profit']) . '</td>
            </tr>
            <tr>
                <td><strong>Net Profit After Tax</strong></td>
                <td>' . format_accounting_number($summary_data[$year]['net_profit_after_tax']) . '</td>
                <td>' . format_accounting_number($summary_data[$year - 1]['net_profit_after_tax']) . '</td>
            </tr>
        </table>';

$html .= '<h2>Statement of Comprehensive Income</h2>';
$html .= '<table border="1" cellpadding="4">
            <tr>
                <td></td>
                <td><strong>' . $year . ' (' . $currency . ')</strong></td>
                <td><strong>' . ($year - 1) . ' (' . $currency . ')</strong></td>
            </tr>
            <tr>
                <td>Total Revenue</td>
                <td>' . format_accounting_number($summary_data[$year]['total_revenue']) . '</td>
                <td>' . format_accounting_number($summary_data[$year - 1]['total_revenue']) . '</td>
            </tr>
            <tr>
                <td>Total Expenditure</td>
                <td>' . format_accounting_number($summary_data[$year]['total_expenditure']) . '</td>
                <td>' . format_accounting_number($summary_data[$year - 1]['total_expenditure']) . '</td>
            </tr>
             <tr>
                <td>Depreciation Expense</td>
                <td>' . format_accounting_number($summary_data[$year]['total_depreciation']) . '</td>
                <td>' . format_accounting_number($summary_data[$year - 1]['total_depreciation']) . '</td>
            </tr>
            <tr>
                <td><strong>Profit Before Tax</strong></td>
                <td><strong>' . format_accounting_number($summary_data[$year]['profit_before_tax']) . '</strong></td>
                <td><strong>' . format_accounting_number($summary_data[$year - 1]['profit_before_tax']) . '</strong></td>
            </tr>
            <tr>
                <td>Income Tax Expense ' . (isset($settings['corporate_tax_rate']) ? '(at ' . $settings['corporate_tax_rate'] . '%)' : '(Individual Rates)') . '</td>
                <td>' . format_accounting_number($summary_data[$year]['income_tax_expense']) . '</td>
                <td>' . format_accounting_number($summary_data[$year - 1]['income_tax_expense']) . '</td>
            </tr>
            <tr>
                <td><strong>Net Profit After Tax</strong></td>
                <td><strong>' . format_accounting_number($summary_data[$year]['net_profit_after_tax']) . '</strong></td>
                <td><strong>' . format_accounting_number($summary_data[$year - 1]['net_profit_after_tax']) . '</strong></td>
            </tr>
        </table>';

// Statement of Financial Position (Balance Sheet)
$html .= '<h2>Statement of Financial Position (Balance Sheet)</h2>';
$html .= '<table border="1" cellpadding="4">
            <tr>
                <td></td>
                <td><strong>' . $year . ' (' . $currency . ')</strong></td>
                <td><strong>' . ($year - 1) . ' (' . $currency . ')</strong></td>
            </tr>
            <tr>
                <td><strong>Assets</strong></td>
                <td></td>
                <td></td>
            </tr>
            <tr>
                <td>Current Assets</td>
                <td>' . format_accounting_number($balance_sheet_data[$year]['current_assets']) . '</td>
                <td>' . format_accounting_number($balance_sheet_data[$year - 1]['current_assets']) . '</td>
            </tr>
            <tr>
                <td>Non-Current Assets</td>
                <td>' . format_accounting_number($balance_sheet_data[$year]['non_current_assets']) . '</td>
                <td>' . format_accounting_number($balance_sheet_data[$year - 1]['non_current_assets']) . '</td>
            </tr>
            <tr>
                <td><strong>Total Assets</strong></td>
                <td><strong>' . format_accounting_number($balance_sheet_data[$year]['total_assets']) . '</strong></td>
                <td><strong>' . format_accounting_number($balance_sheet_data[$year - 1]['total_assets']) . '</strong></td>
            </tr>
            <tr>
                <td><strong>Liabilities and Equity</strong></td>
                <td></td>
                <td></td>
            </tr>
            <tr>
                <td>Current Liabilities</td>
                <td>' . format_accounting_number($balance_sheet_data[$year]['current_liabilities']) . '</td>
                <td>' . format_accounting_number($balance_sheet_data[$year - 1]['current_liabilities']) . '</td>
            </tr>
            <tr>
                <td>Non-Current Liabilities</td>
                <td>' . format_accounting_number($balance_sheet_data[$year]['non_current_liabilities']) . '</td>
                <td>' . format_accounting_number($balance_sheet_data[$year - 1]['non_current_liabilities']) . '</td>
            </tr>
            <tr>
                <td>Equity</td>
                <td>' . format_accounting_number($balance_sheet_data[$year]['equity']) . '</td>
                <td>' . format_accounting_number($balance_sheet_data[$year - 1]['equity']) . '</td>
            </tr>
            <tr>
                <td><strong>Total Liabilities and Equity</strong></td>
                <td><strong>' . format_accounting_number($balance_sheet_data[$year]['total_liabilities_and_equity']) . '</strong></td>
                <td><strong>' . format_accounting_number($balance_sheet_data[$year - 1]['total_liabilities_and_equity']) . '</strong></td>
            </tr>
        </table>';

// Statement of Cash Flows
$html .= '<h2>Statement of Cash Flows</h2>';
$html .= '<table border="1" cellpadding="4">
            <tr>
                <td></td>
                <td><strong>' . $year . ' (' . $currency . ')</strong></td>
                <td><strong>' . ($year - 1) . ' (' . $currency . ')</strong></td>
            </tr>
            <tr>
                <td>Cash flow from operating activities</td>
                <td>' . format_accounting_number($cash_flow_data[$year]['operating_activities']) . '</td>
                <td>' . format_accounting_number($cash_flow_data[$year - 1]['operating_activities']) . '</td>
            </tr>
            <tr>
                <td>Cash flow from investing activities</td>
                <td>' . format_accounting_number($cash_flow_data[$year]['investing_activities']) . '</td>
                <td>' . format_accounting_number($cash_flow_data[$year - 1]['investing_activities']) . '</td>
            </tr>
            <tr>
                <td>Cash flow from financing activities</td>
                <td>' . format_accounting_number($cash_flow_data[$year]['financing_activities']) . '</td>
                <td>' . format_accounting_number($cash_flow_data[$year - 1]['financing_activities']) . '</td>
            </tr>
            <tr>
                <td><strong>Net increase in cash</strong></td>
                <td><strong>' . format_accounting_number($cash_flow_data[$year]['net_increase_in_cash']) . '</strong></td>
                <td><strong>' . format_accounting_number($cash_flow_data[$year - 1]['net_increase_in_cash']) . '</strong></td>
            </tr>
        </table>';

// Statement of Changes in Equity (Comparative)
$equity_data_curr_year = get_equity_data($year, $summary_data);
$equity_data_prev_year = get_equity_data($year - 1, $summary_data);
$html .= '<h2>Statement of Changes in Equity</h2>';
$html .= '<table border="1" cellpadding="4">
            <thead>
                <tr>
                    <th></th>
                    <th>' . $year . ' (' . $currency . ')</th>
                    <th>' . ($year - 1) . ' (' . $currency . ')</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Opening Equity Balance</td>
                    <td>' . format_accounting_number($equity_data_curr_year['opening_balance']) . '</td>
                    <td>' . format_accounting_number($equity_data_prev_year['opening_balance']) . '</td>
                </tr>
                <tr>
                    <td>Net Profit for the Period</td>
                    <td>' . format_accounting_number($equity_data_curr_year['net_profit']) . '</td>
                    <td>' . format_accounting_number($equity_data_prev_year['net_profit']) . '</td>
                </tr>
                <tr>
                    <td><strong>Closing Equity Balance</strong></td>
                    <td><strong>' . format_accounting_number($equity_data_curr_year['closing_balance']) . '</strong></td>
                    <td><strong>' . format_accounting_number($equity_data_prev_year['closing_balance']) . '</strong></td>
                </tr>
            </tbody>
        </table>';

// Notes to the Financial Statements
$html .= '<h2>Notes to the Financial Statements</h2>';
$html .= '<h3>Property, Plant, and Equipment</h3>';
$html .= '<table border="1" cellpadding="4">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Cost</th>
                    <th>Accumulated Depreciation</th>
                    <th>Net Book Value</th>
                </tr>
            </thead>
            <tbody>';
$asset_categories = [];
foreach ($depreciation_details as $asset) {
    if (!isset($asset_categories[$asset['category']])) {
        $asset_categories[$asset['category']] = ['cost' => 0, 'accumulated_depreciation' => 0, 'nbv' => 0];
    }
    $asset_categories[$asset['category']]['cost'] += $asset['purchase_cost'];
    $asset_categories[$asset['category']]['accumulated_depreciation'] += $asset['accumulated_depreciation'];
    $asset_categories[$asset['category']]['nbv'] += $asset['nbv'];
}
foreach ($asset_categories as $category => $values) {
    $html .= '<tr>
                <td>' . $category . '</td>
                <td>' . format_accounting_number($values['cost']) . '</td>
                <td>' . format_accounting_number($values['accumulated_depreciation']) . '</td>
                <td>' . format_accounting_number($values['nbv']) . '</td>
            </tr>';
}
$html .= '</tbody></table>';

// Note on Expenditure Breakdown (Comparative)
$expenditure_breakdown = get_expenditure_breakdown($year);
$all_expense_types = array_unique(array_merge(
    array_column($expenditure_breakdown[$year], 'expense_type'),
    array_column($expenditure_breakdown[$year - 1], 'expense_type')
));
sort($all_expense_types);

if (!empty($all_expense_types)) {
    $html .= '<h3>Expenditure Breakdown</h3>';
    $html .= '<table border="1" cellpadding="4">
                <thead>
                    <tr>
                        <th>Expense Type</th>
                        <th>' . $year . ' (' . $currency . ')</th>
                        <th>' . ($year - 1) . ' (' . $currency . ')</th>
                    </tr>
                </thead>
                <tbody>';
    $year_totals = array_column($expenditure_breakdown[$year], 'total_amount', 'expense_type');
    $prev_year_totals = array_column($expenditure_breakdown[$year - 1], 'total_amount', 'expense_type');

    foreach ($all_expense_types as $type) {
        $html .= '<tr>
                    <td>' . htmlspecialchars(format_expense_name($type)) . '</td>
                    <td>' . format_accounting_number($year_totals[$type] ?? 0) . '</td>
                    <td>' . format_accounting_number($prev_year_totals[$type] ?? 0) . '</td>
                </tr>';
    }
    $html .= '</tbody></table>';
}

// Note on Depreciation Details (Full Schedule)
$depreciation_details = get_depreciation_details($year);
if (!empty($depreciation_details[$year])) {
    $html .= '<h3>Depreciation Details for ' . $year . '</h3>';
    $html .= '<table border="1" cellpadding="4">
                <thead>
                    <tr>
                        <th>Asset Name</th>
                        <th>Category</th>
                        <th>Cost</th>
                        <th>Annual Depreciation</th>
                        <th>Accumulated Depreciation</th>
                        <th>Net Book Value (NBV)</th>
                    </tr>
                </thead>
                <tbody>';
    foreach ($depreciation_details[$year] as $asset) {
        $html .= '<tr>
                    <td>' . htmlspecialchars($asset['name']) . '</td>
                    <td>' . htmlspecialchars($asset['category']) . '</td>
                    <td>' . format_accounting_number($asset['purchase_cost']) . '</td>
                    <td>' . format_accounting_number($asset['depreciation']) . '</td>
                    <td>' . format_accounting_number($asset['accumulated_depreciation']) . '</td>
                    <td>' . format_accounting_number($asset['nbv']) . '</td>
                </tr>';
    }
    $html .= '</tbody></table>';
}


// Tax Computation Note
$tax_computation_note = '<h2>Tax Computation</h2><table border="1" cellpadding="4"><thead><tr><th>Description</th><th>' . $year . ' (' . $currency . ')</th><th>' . ($year - 1) . ' (' . $currency . ')</th></tr></thead><tbody>';
$tax_years = [$year, $year - 1];
$tax_paid_data = [];
foreach ($tax_years as $tax_year) {
    $stmt_tax_paid = $pdo->prepare("SELECT SUM(amount) FROM tax_payments WHERE financial_year = ?");
    $stmt_tax_paid->execute([$tax_year]);
    $tax_paid_data[$tax_year] = $stmt_tax_paid->fetchColumn() ?: 0;
}

$tax_computation_note .= '<tr><td>Profit Before Tax</td><td>' . format_accounting_number($summary_data[$year]['profit_before_tax']) . '</td><td>' . format_accounting_number($summary_data[$year - 1]['profit_before_tax']) . '</td></tr>';
$tax_computation_note .= '<tr><td>Income Tax Expense</td><td>' . format_accounting_number($summary_data[$year]['income_tax_expense']) . '</td><td>' . format_accounting_number($summary_data[$year - 1]['income_tax_expense']) . '</td></tr>';
$tax_computation_note .= '<tr><td>Estimated Tax Paid (Prepaid)</td><td>' . format_accounting_number($tax_paid_data[$year]) . '</td><td>' . format_accounting_number($tax_paid_data[$year - 1]) . '</td></tr>';
$tax_payable_curr = $summary_data[$year]['income_tax_expense'] - $tax_paid_data[$year];
$tax_payable_prev = $summary_data[$year-1]['income_tax_expense'] - $tax_paid_data[$year-1];
$tax_computation_note .= '<tr><td><strong>Tax Payable / (Receivable)</strong></td><td><strong>' . format_accounting_number($tax_payable_curr) . '</strong></td><td><strong>' . format_accounting_number($tax_payable_prev) . '</strong></td></tr>';
$tax_computation_note .= '</tbody></table>';
$html .= $tax_computation_note;


// Formal IFRS Sections
$html .= '<h2>Accounting Policy</h2>';
$html .= '<p>The financial statements have been prepared in accordance with International Financial Reporting Standards (IFRS).</p>';
$html .= '<h2>Director\'s Declaration</h2>';
$html .= '<p>The directors declare that the financial statements and notes, set out on pages 1 to 5, are in accordance with the Companies Act and International Financial Reporting Standards.</p>';
$html .= '<h2>Accountant\'s Declaration</h2>';
$html .= '<p>We, the accountants, declare that to the best of our knowledge and belief, the financial statements are correct and give a true and fair view of the state of the company\'s affairs.</p>';

// Print text using writeHTMLCell()
$pdf->writeHTML($html, true, false, true, false, '');

// Close and output PDF document
$pdf->Output('Financial_Statement_' . $year . '.pdf', 'I');

function format_accounting_number($number, $decimals = 2) {
    if (floatval($number) == 0) {
        return '-';
    }
    if ($number < 0) {
        return '(' . number_format(abs($number), $decimals) . ')';
    }
    return number_format($number, $decimals);
}

function format_expense_name($name) {
    return ucwords(str_replace('_', ' ', $name));
}
