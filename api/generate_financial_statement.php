<?php
// Fatal Error Handler
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        header('Content-Type: text/html');
        echo "<h1>Fatal Error</h1>";
        echo "<p>" . htmlspecialchars($error['message']) . "</p>";
        echo "<p>File: " . htmlspecialchars($error['file']) . " on line " . $error['line'] . "</p>";
    }
});

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once 'db.php';
require_once 'financial_helpers.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die('Please log in to generate reports.');
}

$year = $_GET['year'] ?? date('Y');

try {
    // Fetch all financial data
    $all_financial_data = get_complete_financial_data($year);
    $summary_data = $all_financial_data['summary_data'];
    $balance_sheet_data = $all_financial_data['balance_sheet_data'];
    $cash_flow_data = $all_financial_data['cash_flow_data'];
    $settings = get_settings();
    $currency = htmlspecialchars($settings['default_currency'] ?? 'TZS');

    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor($settings['business_name'] ?? 'System');
$pdf->SetTitle('Financial Statement for ' . $year);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(true);
$pdf->AddPage();

$html = '<h1>Financial Statement for the Year ' . $year . '</h1>';

// 1. Statement of Comprehensive Income (Refactored for COGS/OPEX)
$html .= '<h2>Statement of Comprehensive Income</h2>';
$html .= '<table border="1" cellpadding="4">
            <thead>
                <tr>
                    <th></th>
                    <th><strong>' . $year . ' (' . $currency . ')</strong></th>
                    <th><strong>' . ($year - 1) . ' (' . $currency . ')</strong></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Total Revenue</td>
                    <td>' . format_accounting_number($summary_data[$year]['total_revenue']) . '</td>
                    <td>' . format_accounting_number($summary_data[$year - 1]['total_revenue']) . '</td>
                </tr>
                <tr>
                    <td>Cost of Goods Sold (COGS)</td>
                    <td>' . format_accounting_number($summary_data[$year]['cogs']) . '</td>
                    <td>' . format_accounting_number($summary_data[$year - 1]['cogs']) . '</td>
                </tr>
                <tr style="background-color: #f0f0f0;">
                    <td><strong>Gross Profit</strong></td>
                    <td><strong>' . format_accounting_number($summary_data[$year]['gross_profit']) . '</strong></td>
                    <td><strong>' . format_accounting_number($summary_data[$year - 1]['gross_profit']) . '</strong></td>
                </tr>
                <tr>
                    <td>Operating Expenses (OPEX)</td>
                    <td>' . format_accounting_number($summary_data[$year]['opex']) . '</td>
                    <td>' . format_accounting_number($summary_data[$year - 1]['opex']) . '</td>
                </tr>
                <tr style="background-color: #f0f0f0;">
                    <td><strong>Operating Profit (EBIT)</strong></td>
                    <td><strong>' . format_accounting_number($summary_data[$year]['operating_profit']) . '</strong></td>
                    <td><strong>' . format_accounting_number($summary_data[$year - 1]['operating_profit']) . '</strong></td>
                </tr>
                 <tr>
                    <td>Depreciation Expense</td>
                    <td>' . format_accounting_number($summary_data[$year]['total_depreciation']) . '</td>
                    <td>' . format_accounting_number($summary_data[$year - 1]['total_depreciation']) . '</td>
                </tr>
                <tr style="background-color: #f0f0f0;">
                    <td><strong>Profit Before Tax</strong></td>
                    <td><strong>' . format_accounting_number($summary_data[$year]['profit_before_tax']) . '</strong></td>
                    <td><strong>' . format_accounting_number($summary_data[$year - 1]['profit_before_tax']) . '</strong></td>
                </tr>
                <tr>
                    <td>Income Tax Expense ' . (isset($settings['corporate_tax_rate']) ? '(at ' . $settings['corporate_tax_rate'] . '%)' : '(Individual Rates)') . '</td>
                    <td>' . format_accounting_number($summary_data[$year]['income_tax_expense']) . '</td>
                    <td>' . format_accounting_number($summary_data[$year - 1]['income_tax_expense']) . '</td>
                </tr>
                <tr style="background-color: #e0e0e0;">
                    <td><strong>Net Profit After Tax</strong></td>
                    <td><strong>' . format_accounting_number($summary_data[$year]['net_profit_after_tax']) . '</strong></td>
                    <td><strong>' . format_accounting_number($summary_data[$year - 1]['net_profit_after_tax']) . '</strong></td>
                </tr>
            </tbody>
        </table>';

// 2. Statement of Financial Position (Balance Sheet)
$html .= '<h2>Statement of Financial Position (Balance Sheet)</h2>';
$html .= '<table border="1" cellpadding="4">
            <thead>
                <tr>
                    <th></th>
                    <th><strong>' . $year . ' (' . $currency . ')</strong></th>
                    <th><strong>' . ($year - 1) . ' (' . $currency . ')</strong></th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="3" style="background-color: #f0f0f0;"><strong>ASSETS</strong></td></tr>
                <tr><td colspan="3"><strong>Non-Current Assets</strong></td></tr>
                <tr>
                    <td>Property, Plant & Equipment (Net)</td>
                    <td>' . format_accounting_number($balance_sheet_data[$year]['non_current_assets']) . '</td>
                    <td>' . format_accounting_number($balance_sheet_data[$year - 1]['non_current_assets']) . '</td>
                </tr>
                <tr><td colspan="3"><strong>Current Assets</strong></td></tr>
                <tr>
                    <td>Accounts Receivable</td>
                    <td>' . format_accounting_number($balance_sheet_data[$year]['accounts_receivable']) . '</td>
                    <td>' . format_accounting_number($balance_sheet_data[$year - 1]['accounts_receivable']) . '</td>
                </tr>
                <tr>
                    <td>Cash and Cash Equivalents</td>
                    <td>' . format_accounting_number($balance_sheet_data[$year]['cash_position']) . '</td>
                    <td>' . format_accounting_number($balance_sheet_data[$year - 1]['cash_position']) . '</td>
                </tr>
                <tr style="background-color: #e0e0e0;">
                    <td><strong>Total Assets</strong></td>
                    <td><strong>' . format_accounting_number($balance_sheet_data[$year]['total_assets']) . '</strong></td>
                    <td><strong>' . format_accounting_number($balance_sheet_data[$year - 1]['total_assets']) . '</strong></td>
                </tr>

                <tr><td colspan="3" style="background-color: #f0f0f0;"><strong>LIABILITIES AND EQUITY</strong></td></tr>
                <tr><td colspan="3"><strong>Current Liabilities</strong></td></tr>
                <tr>
                    <td>Accounts Payable</td>
                    <td>' . format_accounting_number($balance_sheet_data[$year]['accounts_payable']) . '</td>
                    <td>' . format_accounting_number($balance_sheet_data[$year - 1]['accounts_payable']) . '</td>
                </tr>
                <tr>
                    <td>Tax Payable</td>
                    <td>' . format_accounting_number($balance_sheet_data[$year]['tax_payable']) . '</td>
                    <td>' . format_accounting_number($balance_sheet_data[$year - 1]['tax_payable']) . '</td>
                </tr>
                 <tr><td colspan="3"><strong>Equity</strong></td></tr>
                <tr>
                    <td>Retained Earnings / Owner\'s Equity</td>
                    <td>' . format_accounting_number($balance_sheet_data[$year]['equity']) . '</td>
                    <td>' . format_accounting_number($balance_sheet_data[$year - 1]['equity']) . '</td>
                </tr>
                <tr style="background-color: #e0e0e0;">
                    <td><strong>Total Liabilities and Equity</strong></td>
                    <td><strong>' . format_accounting_number($balance_sheet_data[$year]['total_liabilities_and_equity']) . '</strong></td>
                    <td><strong>' . format_accounting_number($balance_sheet_data[$year - 1]['total_liabilities_and_equity']) . '</strong></td>
                </tr>
            </tbody>
        </table>';

// 3. Statement of Cash Flows (Indirect Method)
$html .= '<h2>Statement of Cash Flows</h2>';
$html .= '<table border="1" cellpadding="4">
            <thead>
                <tr>
                    <th></th>
                    <th><strong>' . $year . ' (' . $currency . ')</strong></th>
                    <th><strong>' . ($year - 1) . ' (' . $currency . ')</strong></th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="3" style="background-color: #f0f0f0;"><strong>Cash flows from operating activities</strong></td></tr>
                <tr>
                    <td>Profit Before Tax</td>
                    <td>' . format_accounting_number($cash_flow_data[$year]['profit_before_tax']) . '</td>
                    <td>' . format_accounting_number($cash_flow_data[$year - 1]['profit_before_tax']) . '</td>
                </tr>
                <tr>
                    <td>Adjustments for: Depreciation</td>
                    <td>' . format_accounting_number($cash_flow_data[$year]['depreciation_add_back']) . '</td>
                    <td>' . format_accounting_number($cash_flow_data[$year - 1]['depreciation_add_back']) . '</td>
                </tr>
                <tr>
                    <td>(Increase)/Decrease in Accounts Receivable</td>
                    <td>' . format_accounting_number(-$cash_flow_data[$year]['increase_in_ar']) . '</td>
                    <td>' . format_accounting_number(-$cash_flow_data[$year - 1]['increase_in_ar']) . '</td>
                </tr>
                <tr>
                    <td>Increase/(Decrease) in Accounts Payable</td>
                    <td>' . format_accounting_number($cash_flow_data[$year]['increase_in_ap']) . '</td>
                    <td>' . format_accounting_number($cash_flow_data[$year - 1]['increase_in_ap']) . '</td>
                </tr>
                <tr>
                    <td>Income Taxes Paid</td>
                    <td>' . format_accounting_number(-$cash_flow_data[$year]['tax_paid']) . '</td>
                    <td>' . format_accounting_number(-$cash_flow_data[$year - 1]['tax_paid']) . '</td>
                </tr>
                <tr style="background-color: #e0e0e0;">
                    <td><strong>Net cash from operating activities</strong></td>
                    <td><strong>' . format_accounting_number($cash_flow_data[$year]['operating_activities']) . '</strong></td>
                    <td><strong>' . format_accounting_number($cash_flow_data[$year - 1]['operating_activities']) . '</strong></td>
                </tr>

                <tr><td colspan="3" style="background-color: #f0f0f0;"><strong>Cash flows from investing activities</strong></td></tr>
                <tr>
                    <td>Purchase of Property, Plant & Equipment</td>
                    <td>' . format_accounting_number($cash_flow_data[$year]['investing_activities']) . '</td>
                    <td>' . format_accounting_number($cash_flow_data[$year - 1]['investing_activities']) . '</td>
                </tr>

                <tr><td colspan="3" style="background-color: #f0f0f0;"><strong>Cash flows from financing activities</strong></td></tr>
                <tr>
                    <td>Net cash from financing activities</td>
                    <td>' . format_accounting_number($cash_flow_data[$year]['financing_activities']) . '</td>
                    <td>' . format_accounting_number($cash_flow_data[$year - 1]['financing_activities']) . '</td>
                </tr>

                <tr style="background-color: #d0d0d0;">
                    <td><strong>Net Increase/(Decrease) in Cash</strong></td>
                    <td><strong>' . format_accounting_number($cash_flow_data[$year]['net_increase_in_cash']) . '</strong></td>
                    <td><strong>' . format_accounting_number($cash_flow_data[$year - 1]['net_increase_in_cash']) . '</strong></td>
                </tr>
            </tbody>
        </table>';

// 4. Statement of Changes in Equity
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
                <tr style="background-color: #e0e0e0;">
                    <td><strong>Closing Equity Balance</strong></td>
                    <td><strong>' . format_accounting_number($equity_data_curr_year['closing_balance']) . '</strong></td>
                    <td><strong>' . format_accounting_number($equity_data_prev_year['closing_balance']) . '</strong></td>
                </tr>
            </tbody>
        </table>';

// Notes Section (kept similar but ensured variables exist)
$html .= '<h2>Notes to the Financial Statements</h2>';
$depreciation_details = get_depreciation_details($year);

if (!empty($depreciation_details[$year])) {
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
    foreach ($depreciation_details[$year] as $asset) {
        if (!isset($asset_categories[$asset['category']])) {
            $asset_categories[$asset['category']] = ['cost' => 0, 'accumulated_depreciation' => 0, 'nbv' => 0];
        }
        $asset_categories[$asset['category']]['cost'] += $asset['purchase_cost'];
        $asset_categories[$asset['category']]['accumulated_depreciation'] += $asset['accumulated_depreciation'];
        $asset_categories[$asset['category']]['nbv'] += $asset['nbv'];
    }
    foreach ($asset_categories as $category => $values) {
        $html .= '<tr>
                    <td>' . htmlspecialchars($category) . '</td>
                    <td>' . format_accounting_number($values['cost']) . '</td>
                    <td>' . format_accounting_number($values['accumulated_depreciation']) . '</td>
                    <td>' . format_accounting_number($values['nbv']) . '</td>
                </tr>';
    }
    $html .= '</tbody></table>';
}

    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output('Financial_Statement_' . $year . '.pdf', 'I');

} catch (Throwable $e) {
    http_response_code(500);
    echo "<h1>Error Generating Report</h1>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

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
?>
