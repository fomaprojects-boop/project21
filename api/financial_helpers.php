<?php
require_once 'db.php';

// A single function to fetch and structure all data to avoid recursion
function get_complete_financial_data($year, $extra_params = []) {
    // Basic validation
    if (!is_numeric($year) || $year < 2000 || $year > 2100) {
        $year = date('Y');
    }

    $interest_expense = isset($extra_params['interest_expense']) ? floatval($extra_params['interest_expense']) : 0;
    $bank_loans = isset($extra_params['bank_loans']) ? floatval($extra_params['bank_loans']) : 0;
    $share_capital = isset($extra_params['share_capital']) ? floatval($extra_params['share_capital']) : 0;

    // 1. Get summary data (Income Statement)
    $summary_data = get_financial_summary_data($year, $interest_expense);

    // 2. The equity data depends on the summary
    $equity_data = [];
    foreach ($summary_data as $y => $data) {
        $equity_data[$y] = get_equity_data($y, $summary_data, ($y == $year ? $share_capital : 0));
    }

    // 3. Balance sheet data calculation
    $balance_sheet_data = get_balance_sheet_data($year, $summary_data, $equity_data, $bank_loans);

    // 4. Cash flow data (Indirect Method)
    $cash_flow_data = get_cash_flow_data($year, $summary_data, $balance_sheet_data);

    return [
        'summary_data' => $summary_data,
        'balance_sheet_data' => $balance_sheet_data,
        'cash_flow_data' => $cash_flow_data,
    ];
}

function get_expense_breakdown_by_category($year) {
    global $pdo;

    $cogs = 0;
    $opex = 0;
    $detailed_breakdown = ['cogs' => [], 'opex' => []];

    // COGS Keywords (Case-insensitive)
    $cogs_keywords = ['materials', 'material', 'goods', 'production', 'inventory', 'purchase'];

    // 1. Vendor Payout Requests
    $stmt_payout = $pdo->prepare("
        SELECT amount, service_type
        FROM payout_requests
        WHERE YEAR(processed_at) = ?
        AND status IN ('Approved', 'Paid')
    ");
    $stmt_payout->execute([$year]);
    $payouts = $stmt_payout->fetchAll(PDO::FETCH_ASSOC);

    foreach ($payouts as $pay) {
        $type = strtolower(trim($pay['service_type']));
        $amount = floatval($pay['amount']);
        $name = trim($pay['service_type']);

        $is_cogs = false;
        foreach ($cogs_keywords as $keyword) {
            if (strpos($type, $keyword) !== false) {
                $is_cogs = true;
                break;
            }
        }

        if ($is_cogs) {
            $cogs += $amount;
            if (!isset($detailed_breakdown['cogs'][$name])) $detailed_breakdown['cogs'][$name] = 0;
            $detailed_breakdown['cogs'][$name] += $amount;
        } else {
            $opex += $amount;
            if (!isset($detailed_breakdown['opex'][$name])) $detailed_breakdown['opex'][$name] = 0;
            $detailed_breakdown['opex'][$name] += $amount;
        }
    }

    // 2. Internal Expenses (direct_expenses)
    $stmt_exp = $pdo->prepare("
        SELECT amount, expense_type, type, status
        FROM direct_expenses
        WHERE YEAR(date) = ?
    ");
    $stmt_exp->execute([$year]);
    $expenses = $stmt_exp->fetchAll(PDO::FETCH_ASSOC);

    foreach ($expenses as $exp) {
        $amount = floatval($exp['amount']);
        $status = $exp['status'];
        $type_col = isset($exp['type']) && !empty($exp['type']) ? strtolower(trim($exp['type'])) : 'requisition';
        $category = strtolower(trim($exp['expense_type']));
        $name = trim($exp['expense_type']);

        $should_include = false;

        if ($type_col === 'claim') {
            if ($status === 'Approved') {
                $should_include = true;
            }
        } else {
            // Requisition
            if ($status === 'Paid') {
                $should_include = true;
            }
        }

        if ($should_include) {
             $is_cogs = false;
            foreach ($cogs_keywords as $keyword) {
                if (strpos($category, $keyword) !== false) {
                    $is_cogs = true;
                    break;
                }
            }

            if ($is_cogs) {
                $cogs += $amount;
                if (!isset($detailed_breakdown['cogs'][$name])) $detailed_breakdown['cogs'][$name] = 0;
                $detailed_breakdown['cogs'][$name] += $amount;
            } else {
                $opex += $amount;
                if (!isset($detailed_breakdown['opex'][$name])) $detailed_breakdown['opex'][$name] = 0;
                $detailed_breakdown['opex'][$name] += $amount;
            }
        }
    }

    // 3. Payroll
    $stmt_payroll = $pdo->prepare("
        SELECT SUM(pe.net_salary)
        FROM payroll_entries pe
        JOIN payroll_batches pb ON pe.batch_id = pb.id
        WHERE pb.year = ? AND pb.status IN ('approved', 'processed')
    ");
    $stmt_payroll->execute([$year]);
    $payroll_total = floatval($stmt_payroll->fetchColumn() ?: 0);

    $opex += $payroll_total;
    if ($payroll_total > 0) {
        $detailed_breakdown['opex']['Payroll / Salaries'] = $payroll_total;
    }

    return ['cogs' => $cogs, 'opex' => $opex, 'breakdown' => $detailed_breakdown];
}

function get_financial_summary_data($year, $interest_expense_input = 0) {
    global $pdo;

    $settings = get_settings();

    $data = [];
    for ($i = 0; $i <= 1; $i++) {
        $current_year = $year - $i;

        // For comparative year, we don't have inputs, so we assume 0 or need stored data.
        // For now, assume 0 for previous year unless we store it.
        $interest_expense = ($i == 0) ? $interest_expense_input : 0;

        // Revenue
        $stmt_rev = $pdo->prepare("
            SELECT SUM(total_amount - tax_amount)
            FROM invoices
            WHERE YEAR(issue_date) = ?
            AND status NOT IN ('Draft', 'Cancelled', 'Converted')
            AND document_type IN ('Invoice', 'Receipt')
        ");
        $stmt_rev->execute([$current_year]);
        $total_revenue = $stmt_rev->fetchColumn() ?: 0;

        $expenses = get_expense_breakdown_by_category($current_year);
        $cogs = $expenses['cogs'];
        $opex = $expenses['opex'];
        $breakdown = $expenses['breakdown'];

        $gross_profit = $total_revenue - $cogs;
        $operating_profit = $gross_profit - $opex;

        $total_depreciation = calculate_total_depreciation($current_year);

        // EBIT - Interest = Profit Before Tax
        $profit_before_tax = $operating_profit - $total_depreciation - $interest_expense;

        // Tax Logic
        if (isset($settings['corporate_tax_rate']) && !is_null($settings['corporate_tax_rate'])) {
            $tax_rate = $settings['corporate_tax_rate'] / 100;
        } else {
            $tax_rate = 0;
        }

        if (isset($settings['corporate_tax_rate']) && !is_null($settings['corporate_tax_rate'])) {
             $income_tax_expense = ($profit_before_tax > 0) ? $profit_before_tax * $tax_rate : 0;
        } else {
             $income_tax_expense = calculate_individual_income_tax($profit_before_tax);
        }

        $net_profit_after_tax = $profit_before_tax - $income_tax_expense;

        $data[$current_year] = [
            'total_revenue' => $total_revenue,
            'cogs' => $cogs,
            'gross_profit' => $gross_profit,
            'opex' => $opex,
            'operating_profit' => $operating_profit,
            'total_depreciation' => $total_depreciation,
            'interest_expense' => $interest_expense,
            'profit_before_tax' => $profit_before_tax,
            'income_tax_expense' => $income_tax_expense,
            'net_profit_after_tax' => $net_profit_after_tax,
            'tax_rate_used' => (isset($settings['corporate_tax_rate']) ? $settings['corporate_tax_rate'] . '%' : 'Individual Scale'),
            'expense_breakdown' => $breakdown
        ];
    }
    return $data;
}

function calculate_ar_balance($date) {
    global $pdo;
    $stmt_billed = $pdo->prepare("
        SELECT SUM(total_amount)
        FROM invoices
        WHERE issue_date <= ?
        AND status NOT IN ('Draft', 'Cancelled', 'Converted')
        AND document_type IN ('Invoice', 'Receipt')
    ");
    $stmt_billed->execute([$date]);
    $total_billed = $stmt_billed->fetchColumn() ?: 0;

    $stmt_paid = $pdo->prepare("SELECT SUM(amount) FROM invoice_payments WHERE payment_date <= ?");
    $stmt_paid->execute([$date]);
    $total_paid = $stmt_paid->fetchColumn() ?: 0;

    return max(0, $total_billed - $total_paid);
}

function calculate_ap_balance($year) {
    global $pdo;
    $ap_total = 0;

    $stmt_pay = $pdo->prepare("
        SELECT SUM(amount)
        FROM payout_requests
        WHERE YEAR(submitted_at) <= ?
        AND status = 'Submitted'
    ");
    $stmt_pay->execute([$year]);
    $ap_total += ($stmt_pay->fetchColumn() ?: 0);

    $stmt_exp = $pdo->prepare("
        SELECT amount, type, status
        FROM direct_expenses
        WHERE YEAR(date) <= ?
        AND status = 'Approved'
    ");
    $stmt_exp->execute([$year]);
    $expenses = $stmt_exp->fetchAll(PDO::FETCH_ASSOC);

    foreach ($expenses as $exp) {
        $type = isset($exp['type']) && !empty($exp['type']) ? strtolower(trim($exp['type'])) : 'requisition';
        if ($type !== 'claim') {
            $ap_total += $exp['amount'];
        }
    }

    return $ap_total;
}

function get_balance_sheet_data($year, $summary_data, $equity_data, $bank_loans_input = 0) {
    global $pdo;
    $balance_data = [];
    for ($i = 0; $i <= 1; $i++) {
        $current_year = $year - $i;
        $date_end = "$current_year-12-31";

        // Use input for current year, assume 0 for prev year
        $bank_loans = ($i == 0) ? $bank_loans_input : 0;

        // ASSETS
        $stmt_nca = $pdo->prepare("SELECT SUM(purchase_cost) FROM assets WHERE YEAR(purchase_date) <= ?");
        $stmt_nca->execute([$current_year]);
        $gross_fixed_assets = $stmt_nca->fetchColumn() ?: 0;
        $accumulated_depreciation = calculate_accumulated_depreciation($current_year);
        $net_fixed_assets = $gross_fixed_assets - $accumulated_depreciation;

        $stmt_inv = $pdo->prepare("SELECT SUM(purchase_cost) FROM investments WHERE YEAR(purchase_date) <= ?");
        $stmt_inv->execute([$current_year]);
        $total_investments = $stmt_inv->fetchColumn() ?: 0;

        $non_current_assets = $net_fixed_assets + $total_investments;

        $accounts_receivable = calculate_ar_balance($date_end);

        // LIABILITIES
        $accounts_payable = calculate_ap_balance($current_year);

        $stmt_tax_paid = $pdo->prepare("SELECT SUM(amount) FROM tax_payments WHERE financial_year = ?");
        $stmt_tax_paid->execute([$current_year]);
        $prepaid_taxes = $stmt_tax_paid->fetchColumn() ?: 0;

        $income_tax_expense = $summary_data[$current_year]['income_tax_expense'];
        $tax_balance = $income_tax_expense - $prepaid_taxes;
        $income_tax_payable = ($tax_balance > 0) ? $tax_balance : 0;
        $tax_asset = ($tax_balance < 0) ? abs($tax_balance) : 0;

        $current_liabilities = $accounts_payable + $income_tax_payable;
        $non_current_liabilities = $bank_loans;
        $total_liabilities = $current_liabilities + $non_current_liabilities;

        // EQUITY
        $equity = $equity_data[$current_year]['closing_balance']; // Includes Share Capital added in get_equity_data

        $total_liabilities_and_equity = $total_liabilities + $equity;

        // BALANCE CHECK
        $total_assets_target = $total_liabilities_and_equity;
        $other_current_assets = $accounts_receivable + $tax_asset;

        $cash_position = $total_assets_target - $non_current_assets - $other_current_assets;
        $current_assets = $other_current_assets + $cash_position;

        $balance_data[$current_year] = [
            'current_assets' => $current_assets,
            'non_current_assets' => $non_current_assets,
            'total_assets' => $total_assets_target,
            'accounts_receivable' => $accounts_receivable,
            'accounts_payable' => $accounts_payable,
            'tax_payable' => $income_tax_payable,
            'loans_payable' => $bank_loans,
            'current_liabilities' => $current_liabilities,
            'non_current_liabilities' => $non_current_liabilities,
            'equity' => $equity,
            'total_liabilities_and_equity' => $total_liabilities_and_equity,
            'cash_position' => $cash_position,
            'prepaid_taxes' => $prepaid_taxes
        ];
    }
    return $balance_data;
}

function get_cash_flow_data($year, $summary_data, $balance_sheet_data) {
    global $pdo;
    $cash_flow = [];

    for ($i = 0; $i <= 1; $i++) {
        $current_year = $year - $i;
        $prev_year = $current_year - 1;

        // 1. Operating Activities
        $profit_before_tax = $summary_data[$current_year]['profit_before_tax'];
        $depreciation = $summary_data[$current_year]['total_depreciation'];
        $interest_expense = $summary_data[$current_year]['interest_expense']; // Add back interest (Non-operating in some views, but standard is to start with PBT)

        $ar_end = $balance_sheet_data[$current_year]['accounts_receivable'];
        $ar_start = ($i == 0) ? $balance_sheet_data[$prev_year]['accounts_receivable'] : calculate_ar_balance("$prev_year-12-31");
        $increase_in_ar = $ar_end - $ar_start;

        $ap_end = $balance_sheet_data[$current_year]['accounts_payable'];
        $ap_start = ($i == 0) ? $balance_sheet_data[$prev_year]['accounts_payable'] : calculate_ap_balance($prev_year);
        $increase_in_ap = $ap_end - $ap_start;

        $stmt_tax = $pdo->prepare("SELECT SUM(amount) FROM tax_payments WHERE financial_year = ?");
        $stmt_tax->execute([$current_year]);
        $tax_paid = $stmt_tax->fetchColumn() ?: 0;

        $operating_activities = $profit_before_tax + $depreciation - $increase_in_ar + $increase_in_ap - $tax_paid;
        // Interest Paid is usually operating or financing. IAS 7 allows either.
        // We already subtracted Interest in PBT. If we want "Cash Generated from Operations", we add back interest then subtract Interest Paid.
        // For simplicity: Net Cash = PBT... assumes Interest was paid in cash.

        // 2. Investing Activities
        $stmt_assets = $pdo->prepare("SELECT SUM(purchase_cost) FROM assets WHERE YEAR(purchase_date) = ?");
        $stmt_assets->execute([$current_year]);
        $assets_purchased = $stmt_assets->fetchColumn() ?: 0;

        $stmt_inv = $pdo->prepare("SELECT SUM(purchase_cost) FROM investments WHERE YEAR(purchase_date) = ?");
        $stmt_inv->execute([$current_year]);
        $investments_purchased = $stmt_inv->fetchColumn() ?: 0;

        $investing_activities = -($assets_purchased + $investments_purchased);

        // 3. Financing Activities
        // Loans received?
        // Change in Loans Payable
        $loans_end = $balance_sheet_data[$current_year]['loans_payable'];
        $loans_start = ($i == 0) ? $balance_sheet_data[$prev_year]['loans_payable'] : 0;
        $net_loans = $loans_end - $loans_start;

        // Equity issued? (Capital)
        $equity_start_bal = get_equity_data($current_year, $summary_data, 0)['opening_balance']; // Without new capital
        // We don't have a direct way to track new capital injection strictly from year to year without a table.
        // But since we use manual input for Share Capital, we can assume the delta is cash inflow.
        // Simplification: Financing = Net Loans.

        $financing_activities = $net_loans;

        $cash_flow[$current_year] = [
            'profit_before_tax' => $profit_before_tax,
            'depreciation_add_back' => $depreciation,
            'increase_in_ar' => $increase_in_ar,
            'increase_in_ap' => $increase_in_ap,
            'tax_paid' => $tax_paid,
            'operating_activities' => $operating_activities,
            'investing_activities' => $investing_activities,
            'financing_activities' => $financing_activities,
            'net_increase_in_cash' => $operating_activities + $investing_activities + $financing_activities,
        ];
    }
    return $cash_flow;
}

function calculate_total_depreciation($year) {
    global $pdo;

    $depreciation_rates = [
        'Furniture' => 0.125, 'Computer' => 0.375, 'Vehicle' => 0.25,
        'Equipment' => 0.25, 'Other' => 0.10
    ];

    $total_depreciation = 0;
    $stmt = $pdo->prepare("SELECT purchase_cost, category, purchase_date FROM assets WHERE YEAR(purchase_date) <= ?");
    $stmt->execute([$year]);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($assets as $asset) {
        $rate = $depreciation_rates[$asset['category']] ?? 0.10;
        try {
            $purchase_date = new DateTime($asset['purchase_date']);
        } catch (Exception $e) {
            continue;
        }
        $purchase_year = (int)$purchase_date->format('Y');
        $cost = $asset['purchase_cost'];

        $months_active = 0;
        if ($purchase_year < $year) {
            $months_active = 12;
        } elseif ($purchase_year == $year) {
            $m = (int)$purchase_date->format('m');
            $months_active = 12 - $m + 1;
        }

        $annual_expense = $cost * $rate * ($months_active / 12);

        $accumulated_prior = 0;
        for ($y = $purchase_year; $y < $year; $y++) {
            $m_active_y = ($y == $purchase_year) ? (12 - (int)$purchase_date->format('m') + 1) : 12;
            $accumulated_prior += ($cost * $rate * ($m_active_y / 12));
        }

        $remaining_value = $cost - $accumulated_prior;
        $actual_expense = min($annual_expense, max(0, $remaining_value));

        $total_depreciation += $actual_expense;
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
        $rate = $depreciation_rates[$asset['category']] ?? 0.10;
        try {
            $purchase_date = new DateTime($asset['purchase_date']);
        } catch (Exception $e) {
            continue;
        }
        $purchase_year = (int)$purchase_date->format('Y');
        $cost = $asset['purchase_cost'];

        $asset_accumulated = 0;
        for ($y = $purchase_year; $y <= $year; $y++) {
            $months_active = ($y == $purchase_year) ? (12 - (int)$purchase_date->format('m') + 1) : 12;
            $annual_dep = $cost * $rate * ($months_active / 12);
            $asset_accumulated += $annual_dep;
        }
        $total_accumulated += min($asset_accumulated, $cost);
    }
    return $total_accumulated;
}

function get_equity_data($year, $all_summary_data, $share_capital = 0) {
    $opening_balance = 0;
    $start_year = 2020;
    if ($year > $start_year) {
         for ($y = $start_year; $y < $year; $y++) {
             $hist_data = get_financial_summary_data($y); // Recursive safe? No, creates loop if not careful.
             // Optimize: In `get_complete_financial_data`, we passed the array.
             // But standalone, this calls `get_financial_summary_data` again.
             // To prevent infinite recursion, `get_financial_summary_data` assumes default interest.
             // For simplicity here, we trust `$all_summary_data` has historical keys IF populated.
             // If not, we risk recursion.
             // FIX: Only use passed `$all_summary_data` if available, or just sum known net profits.
             // Actually `get_complete_financial_data` populates `$all_summary_data` correctly before calling this.
             // So we should rely on `$all_summary_data` values if present, else calculate.
             if (isset($all_summary_data[$y])) {
                 $opening_balance += $all_summary_data[$y]['net_profit_after_tax'];
             }
         }
    }

    $net_profit = $all_summary_data[$year]['net_profit_after_tax'];
    // Equity = Retained Earnings (Opening + Net Profit) + Share Capital
    $retained_earnings = $opening_balance + $net_profit;
    $total_equity = $retained_earnings + $share_capital;

    return [
        'opening_balance' => $opening_balance,
        'net_profit' => $net_profit,
        'share_capital' => $share_capital,
        'retained_earnings' => $retained_earnings,
        'closing_balance' => $total_equity,
    ];
}

function get_settings() {
    global $pdo;
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    $user_id = $_SESSION['user_id'] ?? 0;

    $stmt = $pdo->prepare("
        SELECT
            s.business_name,
            s.profile_picture_url,
            s.business_address,
            s.business_email,
            s.default_currency,
            u.tin_number,
            u.vrn_number,
            u.corporate_tax_rate,
            s.business_stamp_url
        FROM settings s
        LEFT JOIN users u ON u.id = :user_id
        WHERE s.id = 1
    ");
    $stmt->execute(['user_id' => $user_id]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        $settings = ['business_name' => 'My Company', 'default_currency' => 'TZS'];
    }
    if (empty($settings['default_currency'])) {
        $settings['default_currency'] = 'TZS';
    }
    return $settings;
}

function calculate_individual_income_tax($taxable_income) {
    if ($taxable_income <= 0) { return 0; }
    $annual_income = $taxable_income;
    if ($annual_income <= 3240000) { return 0; }
    elseif ($annual_income <= 6240000) { return ($annual_income - 3240000) * 0.08; }
    elseif ($annual_income <= 9120000) { return 240000 + (($annual_income - 6240000) * 0.20); }
    elseif ($annual_income <= 12000000) { return 816000 + (($annual_income - 9120000) * 0.25); }
    else { return 1536000 + (($annual_income - 12000000) * 0.30); }
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
            $rate = $depreciation_rates[$asset['category']] ?? 0.10;
            try {
                $purchase_date = new DateTime($asset['purchase_date']);
            } catch (Exception $e) {
                continue;
            }
            $purchase_year = (int)$purchase_date->format('Y');
            $cost = $asset['purchase_cost'];

            $previously_accumulated = 0;
            for ($y = $purchase_year; $y < $current_year; $y++) {
                $m_active_y = ($y == $purchase_year) ? (12 - (int)$purchase_date->format('m') + 1) : 12;
                $previously_accumulated += ($cost * $rate * ($m_active_y / 12));
            }

            $months_active = 0;
            if ($purchase_year < $current_year) {
                $months_active = 12;
            } elseif ($purchase_year == $current_year) {
                $m = (int)$purchase_date->format('m');
                $months_active = 12 - $m + 1;
            }
            $annual_expense = $cost * $rate * ($months_active / 12);

            $remaining_value = $cost - $previously_accumulated;
            $current_expense = min($annual_expense, max(0, $remaining_value));

            $accumulated_depreciation = min($previously_accumulated + $current_expense, $cost);

            $nbv = $cost - $accumulated_depreciation;

            $details[] = [
                'name' => $asset['name'], 'category' => $asset['category'],
                'purchase_cost' => $asset['purchase_cost'], 'depreciation' => $current_expense,
                'accumulated_depreciation' => $accumulated_depreciation, 'nbv' => $nbv,
            ];
        }
        $all_details[$current_year] = $details;
    }
    return $all_details;
}

function generate_customer_statement_pdf($customerId, $period, $output_mode = 'download', $tenantId) {
    global $pdo;
    if (!class_exists('TCPDF')) { throw new Exception("TCPDF class not found."); }

    $settings = get_settings();
    $stmt_customer = $pdo->prepare("SELECT name, email, phone, address FROM customers WHERE id = ?");
    $stmt_customer->execute([$customerId]);
    $customer = $stmt_customer->fetch(PDO::FETCH_ASSOC);
    if (!$customer) { throw new Exception("Customer not found."); }

    $dateCondition = "";
    $dateParams = [];
    $endDate = new DateTime();
    $startDate = null;

    switch ($period) {
        case 'day': $dateCondition = " AND i.issue_date = CURDATE()"; break;
        case 'week': $startDate = new DateTime('monday this week'); $dateCondition = " AND i.issue_date >= ?"; $dateParams[] = $startDate->format('Y-m-d'); break;
        case 'month': $startDate = new DateTime('first day of this month'); $dateCondition = " AND i.issue_date >= ?"; $dateParams[] = $startDate->format('Y-m-d'); break;
        case 'year': $startDate = new DateTime('first day of January this year'); $dateCondition = " AND i.issue_date >= ?"; $dateParams[] = $startDate->format('Y-m-d'); break;
    }
    $allParams = array_merge([$customerId], $dateParams, [$customerId], $dateParams);

    $sql = "
        (SELECT i.invoice_number AS number, i.issue_date AS date, i.total_amount AS subtotal, i.tax_amount AS tax, 0 AS paid_amount, i.total_amount AS total FROM invoices i WHERE i.customer_id = ? AND i.document_type != 'Receipt' $dateCondition)
        UNION ALL
        (SELECT i.invoice_number AS number, p.payment_date AS date, 0 AS subtotal, 0 AS tax, p.amount AS paid_amount, 0 AS total FROM invoice_payments p JOIN invoices i ON p.invoice_id = i.id WHERE i.customer_id = ? $dateCondition)
        ORDER BY date ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($allParams);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor($settings['business_name']);
    $pdf->SetTitle('Customer Statement');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 10);

    $html = '<h1>Customer Statement</h1><p>Generated on ' . date('d/m/Y') . '</p>';
    $html .= '<table border="1" cellpadding="5"><thead><tr><th>Date</th><th>Ref</th><th>Debit</th><th>Credit</th></tr></thead><tbody>';
    foreach ($transactions as $t) {
        $html .= '<tr><td>' . $t['date'] . '</td><td>' . $t['number'] . '</td><td>' . number_format($t['total'],2) . '</td><td>' . number_format($t['paid_amount'],2) . '</td></tr>';
    }
    $html .= '</tbody></table>';

    $pdf->writeHTML($html);

    $filename = 'Statement_' . $customerId . '.pdf';
    if ($output_mode === 'save') {
        $path = dirname(__DIR__) . '/uploads/statements/' . $filename;
        $pdf->Output($path, 'F');
        return $path;
    } else {
        $pdf->Output($filename, 'I');
    }
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
                SELECT expense_type, amount as total_amount FROM direct_expenses WHERE YEAR(date) = :year AND status IN ('Approved', 'Paid')
                UNION ALL
                SELECT service_type as expense_type, amount as total_amount FROM payout_requests WHERE YEAR(processed_at) = :year_payout AND status = 'Approved'
            ) as combined_expenses
            GROUP BY expense_type
            ORDER BY total_amount DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['year' => $current_year, 'year_payout' => $current_year]);
        $breakdown_data[$current_year] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $breakdown_data;
}
?>
