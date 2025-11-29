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

    // Custom Header/Footer implementation via extended class or direct write
    // Since we are using standard TCPDF here, we'll implement the header manually on the first page.
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor($settings['business_name'] ?? 'ChatMe System');
    $pdf->SetTitle('Financial Statement ' . $year);

    // Disable default header to implement custom one
    $pdf->setPrintHeader(false);

    // Custom Footer
    $pdf->setFooterData([0,0,0], [0,0,0]);
    $pdf->setFooterFont(['helvetica', '', 8]);

    // Set margins
    $pdf->SetMargins(15, 20, 15);
    $pdf->SetAutoPageBreak(TRUE, 25);

    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 10);

    // --- 1. Custom Header ---
    $logo = $settings['profile_picture_url'] ?? '';
    // Logo (Left)
    if ($logo && file_exists($logo)) { // If local file
         $pdf->Image($logo, 15, 10, 25, '', '', '', 'T', false, 300, '', false, false, 0, false, false, false);
    } elseif ($logo) { // If URL
         @$pdf->Image($logo, 15, 10, 25, '', '', '', 'T', false, 300, '', false, false, 0, false, false, false);
    }

    // Company Details (Right)
    $pdf->SetXY(100, 10);
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(95, 8, strtoupper($settings['business_name'] ?? 'Company Name'), 0, 1, 'R');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetX(100);
    $pdf->MultiCell(95, 5, ($settings['business_address'] ?? '') . "\n" . ($settings['business_email'] ?? '') . "\n" . ($settings['tin_number'] ? "TIN: " . $settings['tin_number'] : ""), 0, 'R');

    // Violet Separator Line
    $pdf->SetLineStyle(array('width' => 0.5, 'color' => array(124, 58, 237))); // #7c3aed
    $pdf->Line(15, 35, 195, 35);
    $pdf->Ln(15); // Spacer after header

    // --- Document Title ---
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->Cell(0, 10, 'Financial Statement for the Year ' . $year, 0, 1, 'C');
    $pdf->Ln(5);

    // Style Definition
    $style = '
    <style>
        h2 { font-family: helvetica; color: #333; font-size: 14pt; font-weight: bold; margin-bottom: 10px; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
        h3 { font-family: helvetica; color: #555; font-size: 11pt; font-weight: bold; margin-top: 15px; }
        table { width: 100%; border-collapse: collapse; border-spacing: 0; margin-bottom: 20px; font-family: helvetica; font-size: 9pt; }
        th { background-color: #7c3aed; color: #ffffff; font-weight: bold; padding: 8px; border: 1px solid #7c3aed; }
        td { padding: 8px; border: 1px solid #e5e7eb; color: #333; }
        .zebra { background-color: #f9fafb; }
        .bold { font-weight: bold; }
        .subtotal { background-color: #f3f4f6; font-weight: bold; }
        .total { background-color: #e5e7eb; font-weight: bold; border-top: 2px solid #333; }
        .text-right { text-align: right; }
    </style>';

    $html = $style;

    // Helper for rows
    function row($label, $val1, $val2, $isBold = false, $bg = '') {
        $class = $bg ? "class=\"$bg\"" : "";
        $style = $isBold ? "font-weight:bold;" : "";
        return "<tr $class><td style=\"$style\">$label</td><td class=\"text-right\" style=\"$style\">$val1</td><td class=\"text-right\" style=\"$style\">$val2</td></tr>";
    }

    // 1. Statement of Comprehensive Income
    $html .= '<h2>Statement of Comprehensive Income</h2>';
    $html .= '<table cellspacing="0" cellpadding="5" border="0">'; // border=0 handled by CSS

    // Header
    $html .= '<thead><tr>
                <th width="50%">Description</th>
                <th width="25%" class="text-right">' . $year . ' (' . $currency . ')</th>
                <th width="25%" class="text-right">' . ($year - 1) . ' (' . $currency . ')</th>
              </tr></thead><tbody>';

    $html .= row('Total Revenue', format_accounting_number($summary_data[$year]['total_revenue']), format_accounting_number($summary_data[$year - 1]['total_revenue']), false, 'zebra');
    $html .= row('Cost of Goods Sold (COGS)', format_accounting_number($summary_data[$year]['cogs']), format_accounting_number($summary_data[$year - 1]['cogs']));
    $html .= row('Gross Profit', format_accounting_number($summary_data[$year]['gross_profit']), format_accounting_number($summary_data[$year - 1]['gross_profit']), true, 'subtotal');

    $html .= row('Operating Expenses (OPEX)', format_accounting_number($summary_data[$year]['opex']), format_accounting_number($summary_data[$year - 1]['opex']));
    $html .= row('Operating Profit (EBIT)', format_accounting_number($summary_data[$year]['operating_profit']), format_accounting_number($summary_data[$year - 1]['operating_profit']), true, 'subtotal');

    $html .= row('Depreciation Expense', format_accounting_number($summary_data[$year]['total_depreciation']), format_accounting_number($summary_data[$year - 1]['total_depreciation']));
    $html .= row('Profit Before Tax', format_accounting_number($summary_data[$year]['profit_before_tax']), format_accounting_number($summary_data[$year - 1]['profit_before_tax']), true, 'subtotal');

    $tax_label = 'Income Tax Expense ' . (isset($settings['corporate_tax_rate']) ? '('.$settings['corporate_tax_rate'].'%)' : '');
    $html .= row($tax_label, format_accounting_number($summary_data[$year]['income_tax_expense']), format_accounting_number($summary_data[$year - 1]['income_tax_expense']));

    $html .= row('Net Profit After Tax', format_accounting_number($summary_data[$year]['net_profit_after_tax']), format_accounting_number($summary_data[$year - 1]['net_profit_after_tax']), true, 'total');

    $html .= '</tbody></table>';
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
    $html .= '<table cellspacing="0" cellpadding="5" border="0">';
    $html .= '<thead><tr><th width="50%">Description</th><th width="25%" class="text-right">' . $year . '</th><th width="25%" class="text-right">' . ($year - 1) . '</th></tr></thead><tbody>';

    // Assets
    $html .= '<tr><td colspan="3" class="subtotal">ASSETS</td></tr>';
    $html .= row('<b>Non-Current Assets</b>', '', '', false, '');
    $html .= row('&nbsp;&nbsp;Property, Plant & Equipment (Net)', format_accounting_number($balance_sheet_data[$year]['non_current_assets']), format_accounting_number($balance_sheet_data[$year - 1]['non_current_assets']));

    $html .= row('<b>Current Assets</b>', '', '', false, '');
    $html .= row('&nbsp;&nbsp;Accounts Receivable', format_accounting_number($balance_sheet_data[$year]['accounts_receivable']), format_accounting_number($balance_sheet_data[$year - 1]['accounts_receivable']), false, 'zebra');
    $html .= row('&nbsp;&nbsp;Cash and Cash Equivalents', format_accounting_number($balance_sheet_data[$year]['cash_position']), format_accounting_number($balance_sheet_data[$year - 1]['cash_position']));

    $html .= row('Total Assets', format_accounting_number($balance_sheet_data[$year]['total_assets']), format_accounting_number($balance_sheet_data[$year - 1]['total_assets']), true, 'total');

    // Liabilities
    $html .= '<tr><td colspan="3" class="subtotal">LIABILITIES AND EQUITY</td></tr>';
    $html .= row('<b>Current Liabilities</b>', '', '', false, '');
    $html .= row('&nbsp;&nbsp;Accounts Payable', format_accounting_number($balance_sheet_data[$year]['accounts_payable']), format_accounting_number($balance_sheet_data[$year - 1]['accounts_payable']), false, 'zebra');
    $html .= row('&nbsp;&nbsp;Tax Payable', format_accounting_number($balance_sheet_data[$year]['tax_payable']), format_accounting_number($balance_sheet_data[$year - 1]['tax_payable']));

    $html .= row('<b>Equity</b>', '', '', false, '');
    $html .= row('&nbsp;&nbsp;Retained Earnings / Owner\'s Equity', format_accounting_number($balance_sheet_data[$year]['equity']), format_accounting_number($balance_sheet_data[$year - 1]['equity']), false, 'zebra');

    $html .= row('Total Liabilities and Equity', format_accounting_number($balance_sheet_data[$year]['total_liabilities_and_equity']), format_accounting_number($balance_sheet_data[$year - 1]['total_liabilities_and_equity']), true, 'total');
    $html .= '</tbody></table>';

    // 3. Statement of Cash Flows
    $html .= '<h2>Statement of Cash Flows</h2>';
    $html .= '<table cellspacing="0" cellpadding="5" border="0">';
    $html .= '<thead><tr><th width="50%">Description</th><th width="25%" class="text-right">' . $year . '</th><th width="25%" class="text-right">' . ($year - 1) . '</th></tr></thead><tbody>';

    $html .= '<tr><td colspan="3" class="subtotal">Cash flows from operating activities</td></tr>';
    $html .= row('Profit Before Tax', format_accounting_number($cash_flow_data[$year]['profit_before_tax']), format_accounting_number($cash_flow_data[$year - 1]['profit_before_tax']));
    $html .= row('Adjustments for: Depreciation', format_accounting_number($cash_flow_data[$year]['depreciation_add_back']), format_accounting_number($cash_flow_data[$year - 1]['depreciation_add_back']), false, 'zebra');
    $html .= row('(Increase)/Decrease in Accounts Receivable', format_accounting_number(-$cash_flow_data[$year]['increase_in_ar']), format_accounting_number(-$cash_flow_data[$year - 1]['increase_in_ar']));
    $html .= row('Increase/(Decrease) in Accounts Payable', format_accounting_number($cash_flow_data[$year]['increase_in_ap']), format_accounting_number($cash_flow_data[$year - 1]['increase_in_ap']), false, 'zebra');
    $html .= row('Income Taxes Paid', format_accounting_number(-$cash_flow_data[$year]['tax_paid']), format_accounting_number(-$cash_flow_data[$year - 1]['tax_paid']));
    $html .= row('Net cash from operating activities', format_accounting_number($cash_flow_data[$year]['operating_activities']), format_accounting_number($cash_flow_data[$year - 1]['operating_activities']), true, 'subtotal');

    $html .= '<tr><td colspan="3" class="subtotal">Cash flows from investing activities</td></tr>';
    $html .= row('Purchase of Property, Plant & Equipment', format_accounting_number($cash_flow_data[$year]['investing_activities']), format_accounting_number($cash_flow_data[$year - 1]['investing_activities']));

    $html .= '<tr><td colspan="3" class="subtotal">Cash flows from financing activities</td></tr>';
    $html .= row('Net cash from financing activities', format_accounting_number($cash_flow_data[$year]['financing_activities']), format_accounting_number($cash_flow_data[$year - 1]['financing_activities']));

    $html .= row('Net Increase/(Decrease) in Cash', format_accounting_number($cash_flow_data[$year]['net_increase_in_cash']), format_accounting_number($cash_flow_data[$year - 1]['net_increase_in_cash']), true, 'total');
    $html .= '</tbody></table>';

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
