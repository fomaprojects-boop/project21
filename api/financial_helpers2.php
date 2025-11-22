<?php
require_once 'db.php';

// A single function to fetch and structure all data to avoid recursion
function get_complete_financial_data($year) {
    // 1. Get summary data (Income Statement)
    $summary_data = get_financial_summary_data($year);

    // 2. The equity data depends on the summary
    $equity_data = [];
    foreach ($summary_data as $y => $data) {
        $equity_data[$y] = get_equity_data($y, $summary_data);
    }

    // 3. Balance sheet data is now calculated by balancing the accounting equation
    $balance_sheet_data = get_balance_sheet_data($year, $summary_data, $equity_data);

    // 4. Cash flow data is calculated last for reporting. It's not used in the balance sheet calculation.
    $cash_flow_data = get_cash_flow_data($year, $summary_data);

    return [
        'summary_data' => $summary_data,
        'balance_sheet_data' => $balance_sheet_data,
        'cash_flow_data' => $cash_flow_data,
    ];
}

function get_financial_summary_data($year) {
    global $pdo;

    $settings = get_settings();
    $tax_rate = ($settings['corporate_tax_rate'] ?? 30.00) / 100;

    $data = [];
    for ($i = 0; $i <= 1; $i++) {
        $current_year = $year - $i;

        $stmt_rev = $pdo->prepare("SELECT SUM(total_amount - tax_amount) FROM invoices WHERE YEAR(issue_date) = ? AND status = 'Paid'");
        $stmt_rev->execute([$current_year]);
        $total_revenue = $stmt_rev->fetchColumn() ?: 0;

        $stmt_exp = $pdo->prepare("SELECT SUM(amount) FROM direct_expenses WHERE YEAR(date) = ? AND status IN ('Approved', 'Paid') AND expense_type != 'asset_purchase'");
        $stmt_exp->execute([$current_year]);
        $direct_expenditure = $stmt_exp->fetchColumn() ?: 0;

        $stmt_payouts = $pdo->prepare("SELECT SUM(amount) FROM payout_requests WHERE YEAR(processed_at) = ? AND status = 'Approved'");
        $stmt_payouts->execute([$current_year]);
        $payout_expenditure = $stmt_payouts->fetchColumn() ?: 0;

        $stmt_payroll = $pdo->prepare("
            SELECT SUM(pe.net_salary)
            FROM payroll_entries pe
            JOIN payroll_batches pb ON pe.batch_id = pb.id
            WHERE pb.year = ? AND pb.status IN ('approved', 'processed')
        ");
        $stmt_payroll->execute([$current_year]);
        $payroll_expenditure = $stmt_payroll->fetchColumn() ?: 0;

        $total_expenditure = $direct_expenditure + $payout_expenditure + $payroll_expenditure;

        $total_depreciation = calculate_total_depreciation($current_year);
        $profit_before_tax = $total_revenue - $total_expenditure - $total_depreciation;

        // Determine tax calculation method
        if (isset($settings['corporate_tax_rate']) && !is_null($settings['corporate_tax_rate'])) {
            // Corporate Tax Logic
            $tax_rate = $settings['corporate_tax_rate'] / 100;
            $income_tax_expense = ($profit_before_tax > 0) ? $profit_before_tax * $tax_rate : 0;
        } else {
            // Individual Income Tax Logic (Sole Proprietor)
            $income_tax_expense = calculate_individual_income_tax($profit_before_tax);
        }

        $net_profit_after_tax = $profit_before_tax - $income_tax_expense;

        $data[$current_year] = [
            'total_revenue' => $total_revenue,
            'total_expenditure' => $total_expenditure,
            'gross_profit' => $total_revenue - $total_expenditure,
            'total_depreciation' => $total_depreciation,
            'profit_before_tax' => $profit_before_tax,
            'income_tax_expense' => $income_tax_expense,
            'net_profit_after_tax' => $net_profit_after_tax,
        ];
    }
    return $data;
}

function get_balance_sheet_data($year, $summary_data, $equity_data) {
    global $pdo;
    $balance_data = [];
    for ($i = 0; $i <= 1; $i++) {
        $current_year = $year - $i;

        // ASSETS SIDE
        $stmt_nca = $pdo->prepare("SELECT SUM(purchase_cost) FROM assets WHERE YEAR(purchase_date) <= ?");
        $stmt_nca->execute([$current_year]);
        $gross_fixed_assets = $stmt_nca->fetchColumn() ?: 0;
        $accumulated_depreciation = calculate_accumulated_depreciation($current_year);
        $net_fixed_assets = $gross_fixed_assets - $accumulated_depreciation;

        $stmt_inv = $pdo->prepare("SELECT SUM(purchase_cost) FROM investments WHERE YEAR(purchase_date) <= ?");
        $stmt_inv->execute([$current_year]);
        $total_investments = $stmt_inv->fetchColumn() ?: 0;
        $non_current_assets = $net_fixed_assets + $total_investments;

        $stmt_ar = $pdo->prepare("SELECT SUM(total_amount) FROM invoices WHERE YEAR(issue_date) = ? AND status IN ('Sent', 'Overdue')");
        $stmt_ar->execute([$current_year]);
        $accounts_receivable = $stmt_ar->fetchColumn() ?: 0;

        // LIABILITIES AND EQUITY SIDE
        $stmt_ap = $pdo->prepare("SELECT SUM(amount) FROM direct_expenses WHERE YEAR(date) = ? AND status IN ('Approved', 'Approved for Payment')");
        $stmt_ap->execute([$current_year]);
        $accounts_payable = $stmt_ap->fetchColumn() ?: 0;

        $stmt_tax_paid = $pdo->prepare("SELECT SUM(amount) FROM tax_payments WHERE financial_year = ?");
        $stmt_tax_paid->execute([$current_year]);
        $prepaid_taxes = $stmt_tax_paid->fetchColumn() ?: 0;

        $income_tax_expense = $summary_data[$current_year]['income_tax_expense'];
        $tax_balance = $income_tax_expense - $prepaid_taxes;
        $income_tax_payable = ($tax_balance > 0) ? $tax_balance : 0;
        $tax_asset = ($tax_balance < 0) ? abs($tax_balance) : 0;

        $current_liabilities = $accounts_payable + $income_tax_payable;
        $non_current_liabilities = 0; // Assuming no long-term debt
        $total_liabilities = $current_liabilities + $non_current_liabilities;

        $equity = $equity_data[$current_year]['closing_balance'];
        $total_liabilities_and_equity = $total_liabilities + $equity;

        // BALANCE THE EQUATION
        $total_assets = $total_liabilities_and_equity;
        $other_current_assets = $accounts_receivable + $tax_asset;
        $cash_position = $total_assets - $non_current_assets - $other_current_assets;
        $current_assets = $other_current_assets + $cash_position;

        $balance_data[$current_year] = [
            'current_assets' => $current_assets,
            'non_current_assets' => $non_current_assets,
            'total_assets' => $total_assets,
            'current_liabilities' => $current_liabilities,
            'non_current_liabilities' => $non_current_liabilities,
            'equity' => $equity,
            'total_liabilities_and_equity' => $total_liabilities_and_equity,
            'cash_position' => $cash_position
        ];
    }
    return $balance_data;
}

function get_cash_flow_data($year, $summary_data) {
    global $pdo;
    $cash_flow = [];
    for ($i = 0; $i <= 1; $i++) {
        $current_year = $year - $i;
        $summary = $summary_data[$current_year];

        $stmt_tax = $pdo->prepare("SELECT SUM(amount) FROM tax_payments WHERE financial_year = ?");
        $stmt_tax->execute([$current_year]);
        $tax_paid = $stmt_tax->fetchColumn() ?: 0;
        $operating_activities = $summary['total_revenue'] - $summary['total_expenditure'] - $tax_paid;

        $stmt_assets = $pdo->prepare("SELECT SUM(purchase_cost) FROM assets WHERE YEAR(purchase_date) = ?");
        $stmt_assets->execute([$current_year]);
        $assets_purchased = $stmt_assets->fetchColumn() ?: 0;

        $stmt_inv = $pdo->prepare("SELECT SUM(purchase_cost) FROM investments WHERE YEAR(purchase_date) = ?");
        $stmt_inv->execute([$current_year]);
        $investments_purchased = $stmt_inv->fetchColumn() ?: 0;

        $investing_activities = -($assets_purchased + $investments_purchased);
        $financing_activities = 0;

        $cash_flow[$current_year] = [
            'operating_activities' => $operating_activities,
            'investing_activities' => $investing_activities,
            'financing_activities' => $financing_activities,
            'net_increase_in_cash' => $operating_activities + $investing_activities + $financing_activities,
        ];
    }
    return $cash_flow;
}

function get_equity_data($year, $all_summary_data) {
    $opening_balance = 0;
    for ($y = 2020; $y < $year; $y++) {
        $opening_balance += $all_summary_data[$y]['net_profit_after_tax'];
    }
    $net_profit = $all_summary_data[$year]['net_profit_after_tax'];
    return [
        'opening_balance' => $opening_balance,
        'net_profit' => $net_profit,
        'closing_balance' => $opening_balance + $net_profit,
    ];
}
// Other helper functions remain the same
function calculate_total_depreciation($year) {
    global $pdo;

    $depreciation_rates = [
        'Furniture' => 0.125, 'Computer' => 0.375, 'Vehicle' => 0.25,
        'Equipment' => 0.25, 'Other' => 0.10
    ];

    $total_depreciation = 0;
    $stmt = $pdo->prepare("SELECT purchase_cost, category FROM assets WHERE YEAR(purchase_date) <= ?");
    $stmt->execute([$year]);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($assets as $asset) {
        $rate = $depreciation_rates[$asset['category']] ?? 0;
        $total_depreciation += $asset['purchase_cost'] * $rate;
    }
    return $total_depreciation;
}

function calculate_accumulated_depreciation($year) {
    global $pdo;
    $depreciation_rates = [
        'Furniture' => 0.125, 'Computer' => 0.375, 'Vehicle' => 0.25,
        'Equipment' => 0.25, 'Other' => 0.10
    ];
    $total_accumulated = 0;
    $stmt = $pdo->prepare("SELECT purchase_cost, category, purchase_date FROM assets WHERE YEAR(purchase_date) <= ?");
    $stmt->execute([$year]);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($assets as $asset) {
        $rate = $depreciation_rates[$asset['category']] ?? 0;
        $purchase_year = date('Y', strtotime($asset['purchase_date']));
        $years_depreciated = $year - $purchase_year + 1;
        $accumulated = ($asset['purchase_cost'] * $rate) * $years_depreciated;
        $total_accumulated += min($accumulated, $asset['purchase_cost']);
    }
    return $total_accumulated;
}

function get_depreciation_details($year) {
    global $pdo;
    $depreciation_rates = [
        'Furniture' => 0.125, 'Computer' => 0.375, 'Vehicle' => 0.25,
        'Equipment' => 0.25, 'Other' => 0.10
    ];

    $all_details = [];
    for ($i = 0; $i <= 1; $i++) {
        $current_year = $year - $i;
        $stmt = $pdo->prepare("SELECT name, category, purchase_cost, purchase_date FROM assets WHERE YEAR(purchase_date) <= ?");
        $stmt->execute([$current_year]);
        $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $details = [];
        foreach ($assets as $asset) {
            $rate = $depreciation_rates[$asset['category']] ?? 0;
            $purchase_year = date('Y', strtotime($asset['purchase_date']));
            $years_depreciated = $current_year - $purchase_year + 1;
            $annual_depreciation = $asset['purchase_cost'] * $rate;
            $accumulated_depreciation = $annual_depreciation * $years_depreciated;
            $accumulated_depreciation = min($accumulated_depreciation, $asset['purchase_cost']);
            $nbv = $asset['purchase_cost'] - $accumulated_depreciation;

            $details[] = [
                'name' => $asset['name'], 'category' => $asset['category'],
                'purchase_cost' => $asset['purchase_cost'], 'depreciation' => $annual_depreciation,
                'accumulated_depreciation' => $accumulated_depreciation, 'nbv' => $nbv,
            ];
        }
        $all_details[$current_year] = $details;
    }
    return $all_details;
}

function get_expenditure_details($year) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT date, reference, amount FROM direct_expenses WHERE YEAR(date) = ? AND status = 'Approved' AND expense_type != 'asset_purchase'");
    $stmt->execute([$year]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_expenditure_breakdown($year) {
    global $pdo;
    $breakdown_data = [];

    for ($i = 0; $i <= 1; $i++) {
        $current_year = $year - $i;
        $sql = "
            SELECT
                expense_type,
                SUM(total_amount) as total_amount
            FROM (
                -- Direct Expenses
                SELECT
                    expense_type,
                    amount as total_amount
                FROM direct_expenses
                WHERE
                    YEAR(date) = :year
                    AND status IN ('Approved', 'Paid')
                    AND expense_type != 'asset_purchase'

                UNION ALL

                -- Vendor Payouts for Services
                SELECT
                    service_type as expense_type,
                    amount as total_amount
                FROM payout_requests
                WHERE
                    YEAR(processed_at) = :year_payout
                    AND status = 'Approved'
                    AND service_type != 'Goods Sold' -- Exclude goods
            ) as combined_expenses
            GROUP BY
                expense_type
            ORDER BY
                total_amount DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['year' => $current_year, 'year_payout' => $current_year]);
        $breakdown_data[$current_year] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $breakdown_data;
}

function get_settings() {
    global $pdo;
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    $user_id = $_SESSION['user_id'] ?? 0;

    // Fetch settings from both tables using a JOIN
    $stmt = $pdo->prepare("
        SELECT 
            s.business_name, 
            s.profile_picture_url,
            s.business_address,
            s.business_email,
            s.default_currency,
            u.tin_number,
            u.vrn_number,
            u.business_stamp_url
        FROM settings s
        LEFT JOIN users u ON u.id = :user_id
        WHERE s.id = 1
    ");
    $stmt->execute(['user_id' => $user_id]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    // Set defaults if settings are not found
    if (!$settings) {
        return [
            'business_name' => 'My Company',
            'profile_picture_url' => null,
            'business_address' => 'P.O BOX 1234, City, Country',
            'business_email' => 'info@example.com',
            'default_currency' => 'TZS',
            'tin_number' => '123-456-789',
            'vrn_number' => null,
            'business_stamp_url' => null,
        ];
    }
    if (empty($settings['default_currency'])) {
        $settings['default_currency'] = 'TZS';
    }
    return $settings;
}

function calculate_individual_income_tax($taxable_income) {
    if ($taxable_income <= 0) {
        return 0;
    }

    $annual_income = $taxable_income;
    $tax = 0;

    if ($annual_income <= 3240000) {
        $tax = 0;
    } elseif ($annual_income <= 6240000) {
        $tax = ($annual_income - 3240000) * 0.08;
    } elseif ($annual_income <= 9120000) {
        $tax = 240000 + (($annual_income - 6240000) * 0.20);
    } elseif ($annual_income <= 12000000) {
        $tax = 816000 + (($annual_income - 9120000) * 0.25);
    } else {
        $tax = 1536000 + (($annual_income - 12000000) * 0.30);
    }

    return $tax;
}

function generate_customer_statement_pdf($customerId, $period, $output_mode = 'download', $tenantId) {
    global $pdo;

    if (!class_exists('TCPDF')) {
        throw new Exception("TCPDF class not found. Please ensure Composer's autoload is included.");
    }

    try {
        // Step 1: Fetch Settings and Customer Info
        $settings = get_settings();
        $stmt_customer = $pdo->prepare("SELECT name, email, phone, address FROM customers WHERE id = ?");
        $stmt_customer->execute([$customerId]);
        $customer = $stmt_customer->fetch(PDO::FETCH_ASSOC);

        if (!$customer) {
            throw new Exception("Customer not found.");
        }
        
        // Step 2: Determine Date Range and Fetch Data
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

        // Fetch both invoices and payments to create a unified transaction list
        $allParams = array_merge([$customerId], $dateParams, [$customerId], $dateParams);
        $sql = "
            (SELECT 
                i.invoice_number AS number, 
                i.issue_date AS date, 
                i.total_amount AS subtotal,
                i.tax_amount AS tax,
                0 AS paid_amount,
                i.total_amount AS total
            FROM invoices i 
            WHERE i.customer_id = ? AND i.document_type != 'Receipt' $dateCondition)
            UNION ALL
            (SELECT 
                i.invoice_number AS number,
                p.payment_date AS date,
                0 AS subtotal,
                0 AS tax,
                p.amount AS paid_amount,
                0 AS total
            FROM invoice_payments p 
            JOIN invoices i ON p.invoice_id = i.id 
            WHERE i.customer_id = ? $dateCondition)
            ORDER BY date ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($allParams);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate totals
        $total_billed = array_sum(array_column($transactions, 'total'));
        $total_paid = array_sum(array_column($transactions, 'paid_amount'));
        $balance_due = $total_billed - $total_paid;
        
        // Step 3: Generate PDF
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor($settings['business_name'] ?? 'System');
        $pdf->SetTitle('Customer Statement for ' . $customer['name']);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 10);

        // --- PDF Content ---

        // Logo
        if (!empty($settings['profile_picture_url'])) {
            // Use the full URL, suppressing errors in case the image is not accessible
            // This prevents the entire PDF generation from failing.
            @$pdf->Image($settings['profile_picture_url'], 150, 15, 40, 0, '', '', 'T', false, 300, '', false, false, 0, false, false, false);
        }
        $pdf->Ln(5);

        // Business Details
        $business_details = '<b>' . strtoupper(htmlspecialchars($settings['business_name'] ?? '')) . '</b><br>';
        $business_details .= 'Tax #: ' . htmlspecialchars($settings['tin_number'] ?? '') . '<br>';
        $business_details .= htmlspecialchars($settings['business_email'] ?? '') . '<br>';
        $business_details .= nl2br(htmlspecialchars($settings['business_address'] ?? ''));
        $pdf->writeHTMLCell(80, 0, 15, 15, $business_details, 0, 1, 0, true, 'L', true);

        // Title
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Customer Statement for ' . htmlspecialchars($customer['name']), 0, 1, 'L');
        $pdf->Ln(5);
        
        // Customer Details
        $customer_details = htmlspecialchars($customer['name']) . '<br>';
        $customer_details .= htmlspecialchars($customer['email'] ?? '') . '<br>';
        $customer_details .= htmlspecialchars($customer['phone'] ?? '') . '<br>';
        $customer_details .= nl2br(htmlspecialchars($customer['address'] ?? ''));
        $pdf->writeHTMLCell(80, 0, 15, $pdf->GetY(), $customer_details, 0, 1, 0, true, 'L', true);
        $pdf->Ln(10);
        
        // Table
        $pdf->SetFont('helvetica', '', 9);
        $header = ['Number', 'Date', 'Subtotal', 'Tax', 'Paid Amount', 'Total'];
        $w = [30, 30, 30, 20, 35, 35];
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetTextColor(0);
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->SetFont('helvetica', 'B', 9);
        for($i = 0; $i < count($header); $i++) {
            $pdf->Cell($w[$i], 7, $header[$i], 1, 0, 'C', 1);
        }
        $pdf->Ln();
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetFillColor(255);
        
        if (empty($transactions)) {
             $pdf->Cell(array_sum($w), 10, 'No transactions for this period.', 1, 1, 'C');
        } else {
            foreach ($transactions as $row) {
                 $pdf->Cell($w[0], 6, $row['number'], 'LR', 0, 'L');
                 $pdf->Cell($w[1], 6, date('d/m/Y', strtotime($row['date'])), 'LR', 0, 'L');
                 $pdf->Cell($w[2], 6, number_format($row['subtotal'], 2), 'LR', 0, 'R');
                 $pdf->Cell($w[3], 6, number_format($row['tax'], 2), 'LR', 0, 'R');
                 $pdf->Cell($w[4], 6, number_format($row['paid_amount'], 2), 'LR', 0, 'R');
                 $pdf->Cell($w[5], 6, 'Sh ' . number_format($row['total'], 2), 'LR', 1, 'R');
            }
        }
        $pdf->Cell(array_sum($w), 0, '', 'T');
        $pdf->Ln(5);
        
        // Summary
        $summaryX = 140;
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetX($summaryX);
        $pdf->Cell(30, 7, 'Total', 0, 0, 'R');
        $pdf->Cell(30, 7, number_format($total_billed, 2) . ' TZS', 0, 1, 'R');
        $pdf->SetX($summaryX);
        $pdf->SetTextColor(0, 128, 0);
        $pdf->Cell(30, 7, 'Paid Amount', 0, 0, 'R');
        $pdf->Cell(30, 7, number_format($total_paid, 2) . ' TZS', 0, 1, 'R');
        $pdf->SetX($summaryX);
        $pdf->SetTextColor(255, 0, 0);
        $pdf->Cell(30, 7, 'Balance Due', 0, 0, 'R');
        $pdf->Cell(30, 7, number_format($balance_due, 2) . ' TZS', 0, 1, 'R');
        $pdf->SetTextColor(0);
        
        // Stamp / Signature
        if (!empty($settings['business_stamp_url'])) {
            // Use the full URL, suppressing errors.
            @$pdf->Image($settings['business_stamp_url'], 15, $pdf->GetY() + 10, 30, 0, '', '', '', false, 300, '', false, false, 0, false, false, false);
        }
        
        // Step 4: Output Handling
        $safe_customer_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $customer['name']);
        $pdf_filename = 'Statement_' . $safe_customer_name . '_' . date('Ymd_His') . '.pdf';

        if ($output_mode === 'save') {
            $upload_dir = dirname(__DIR__) . '/uploads/statements/';
            if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
            $filepath = $upload_dir . $pdf_filename;
            $pdf->Output($filepath, 'F');
            return $filepath;
        } else {
            $pdf->Output($pdf_filename, 'I');
            return null;
        }
    } catch (Exception $e) {
        error_log("PDF Generation Error: " . $e->getMessage());
        throw $e;
    }
}
?>