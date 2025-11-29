<?php
require_once 'db.php';

// --- Currency Conversion Logic ---
function convert_currency($amount, $target_currency, $exchange_rate = 1) {
    if (!$amount || !is_numeric($amount)) return 0;

    // Assume Base Currency is TZS.
    $base_currency = 'TZS';

    // No conversion needed if currencies match
    if ($target_currency === $base_currency) {
        return floatval($amount);
    }

    // Logic:
    // If Base = TZS and Target = USD (Stronger), divide by rate.
    // If rate is 1 or invalid, return original.
    if ($exchange_rate <= 0) $exchange_rate = 1;

    return floatval($amount) / $exchange_rate;
}

// A single function to fetch and structure all data to avoid recursion
function get_complete_financial_data($year, $extra_params = []) {
    // Basic validation
    if (!is_numeric($year) || $year < 2000 || $year > 2100) {
        $year = date('Y');
    }

    // Capture Manual Inputs (Assumed in Base Currency TZS)
    $interest_expense = isset($extra_params['interest_expense']) ? floatval($extra_params['interest_expense']) : 0;
    $bank_loans = isset($extra_params['bank_loans']) ? floatval($extra_params['bank_loans']) : 0;
    $share_capital = isset($extra_params['share_capital']) ? floatval($extra_params['share_capital']) : 0;

    // 1. Get summary data (Income Statement)
    $summary_data = get_financial_summary_data($year, $interest_expense);

    // 2. The equity data depends on the summary
    $equity_data = [];
    foreach ($summary_data as $y => $data) {
        $cap_input = ($y == $year) ? $share_capital : 0;
        $equity_data[$y] = get_equity_data($y, $summary_data, $cap_input);
    }

    // 3. Balance sheet data calculation
    $balance_sheet_data = get_balance_sheet_data($year, $summary_data, $equity_data, $bank_loans);

    // 4. Cash flow data (Indirect Method)
    $cash_flow_data = get_cash_flow_data($year, $summary_data, $balance_sheet_data);

    // 5. Notes Data (PPE Details and Financial Investments)
    // We fetch them here so we can convert them centrally
    $ppe_details = get_depreciation_details($year);
    $investment_details = get_financial_investments_details($year);

    // --- APPLY CURRENCY CONVERSION ---
    $settings = get_settings();
    $target_currency = $settings['default_currency'] ?? 'TZS';
    $exchange_rate = isset($settings['exchange_rate']) ? floatval($settings['exchange_rate']) : 1;

    if ($target_currency !== 'TZS') {
        // Helper to recursively convert amounts in an array
        $recursive_convert = function(&$item, $key) use ($target_currency, $exchange_rate) {
            // List of keys that represent monetary values to be converted
            // We include generic keys like 'cost', 'amount', 'nbv', 'purchase_cost' for the Notes
            $monetary_keys = [
                'total_revenue', 'cogs', 'gross_profit', 'opex', 'operating_profit',
                'total_depreciation', 'interest_expense', 'profit_before_tax',
                'income_tax_expense', 'net_profit_after_tax', 'opening_balance',
                'net_profit', 'share_capital', 'retained_earnings', 'closing_balance',
                'current_assets', 'non_current_assets', 'net_ppe', 'financial_investments',
                'total_assets', 'accounts_receivable', 'accounts_payable', 'tax_payable',
                'tax_receivable', 'loans_payable', 'shareholder_loan', 'current_liabilities',
                'non_current_liabilities', 'equity', 'total_liabilities_and_equity',
                'cash_position', 'prepaid_taxes', 'depreciation_add_back', 'increase_in_ar',
                'increase_in_ap', 'tax_paid', 'operating_activities', 'investing_activities',
                'financing_activities', 'net_increase_in_cash', 'purchase_cost', 'cost',
                'accumulated_depreciation', 'nbv', 'amount', 'depreciation'
            ];

            if (in_array($key, $monetary_keys) && is_numeric($item)) {
                $item = convert_currency($item, $target_currency, $exchange_rate);
            }
        };

        // Apply conversion to main datasets
        array_walk_recursive($summary_data, $recursive_convert);
        array_walk_recursive($balance_sheet_data, $recursive_convert);
        array_walk_recursive($cash_flow_data, $recursive_convert);
        array_walk_recursive($equity_data, $recursive_convert); // Convert Equity Data
        array_walk_recursive($ppe_details, $recursive_convert);
        array_walk_recursive($investment_details, $recursive_convert);

        // Special handling for dynamic keys in 'expense_breakdown' (Note 2)
        // The structure is $summary_data[$year]['expense_breakdown']['cogs']['Rent'] = 1000
        // Since 'Rent' is dynamic, it's not in monetary_keys.
        // We must iterate explicitly.
        foreach ($summary_data as $y => &$data) {
            if (isset($data['expense_breakdown'])) {
                foreach (['cogs', 'opex'] as $cat) {
                    if (isset($data['expense_breakdown'][$cat])) {
                        foreach ($data['expense_breakdown'][$cat] as $k => &$v) {
                            $v = convert_currency($v, $target_currency, $exchange_rate);
                        }
                    }
                }
            }
        }
    }

    return [
        'summary_data' => $summary_data,
        'balance_sheet_data' => $balance_sheet_data,
        'cash_flow_data' => $cash_flow_data,
        'equity_data' => $equity_data, // Return Converted Equity Data
        'ppe_details' => $ppe_details,
        'investment_details' => $investment_details
    ];
}

// Lightweight function to calculate Net Profit for history without overhead
function calculate_annual_net_profit($year) {
    global $pdo;

    // Revenue
    $stmt_rev = $pdo->prepare("
        SELECT SUM(total_amount - tax_amount)
        FROM invoices
        WHERE YEAR(issue_date) = ?
        AND status NOT IN ('Draft', 'Cancelled', 'Converted')
        AND document_type IN ('Invoice', 'Receipt')
    ");
    $stmt_rev->execute([$year]);
    $revenue = $stmt_rev->fetchColumn() ?: 0;

    // Expenses (Simplified Logic for History)
    $expenses = get_expense_breakdown_by_category($year);
    $total_expenses = $expenses['cogs'] + $expenses['opex'];

    $depreciation = calculate_total_depreciation($year);

    $interest = 0;

    $profit_before_tax = $revenue - $total_expenses - $depreciation - $interest;

    // Tax
    $settings = get_settings();
    if (isset($settings['corporate_tax_rate']) && !is_null($settings['corporate_tax_rate'])) {
        $tax_rate = $settings['corporate_tax_rate'] / 100;
        $tax = ($profit_before_tax > 0) ? $profit_before_tax * $tax_rate : 0;
    } else {
        $tax = calculate_individual_income_tax($profit_before_tax);
    }

    return $profit_before_tax - $tax;
}

function get_expense_breakdown_by_category($year) {
    global $pdo;

    $cogs = 0;
    $opex = 0;
    $detailed_breakdown = ['cogs' => [], 'opex' => []];

    // COGS Keywords (Case-insensitive)
    $cogs_keywords = ['materials', 'material', 'goods', 'production', 'inventory', 'purchase'];

    // 1. Vendor Payout Requests
    // Safety check for table existence/columns could go here, but per instructions we use 'submitted_at'
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

    // 3. Payroll (Use Gross Salary: Basic + Allowances)
    $stmt_payroll = $pdo->prepare("
        SELECT SUM(pe.basic_salary + pe.allowances)
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

    // Use submitted_at as per requirements.
    // Fallback logic handled by caller or user confirmation of schema.
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

function get_financial_investments_total($year) {
    global $pdo;
    // Financial Investments (Shares, Bonds, etc.) are NOT depreciated.
    $stmt = $pdo->prepare("SELECT SUM(purchase_cost) FROM investments WHERE YEAR(purchase_date) <= ?");
    $stmt->execute([$year]);
    return $stmt->fetchColumn() ?: 0;
}

function get_balance_sheet_data($year, $summary_data, $equity_data, $bank_loans_input = 0) {
    global $pdo;
    $balance_data = [];
    for ($i = 0; $i <= 1; $i++) {
        $current_year = $year - $i;
        $date_end = "$current_year-12-31";

        $bank_loans = ($i == 0) ? $bank_loans_input : 0;

        // ASSETS
        // 1. PPE (Tangible Assets from 'assets' table)
        $stmt_nca = $pdo->prepare("SELECT SUM(purchase_cost) FROM assets WHERE YEAR(purchase_date) <= ?");
        $stmt_nca->execute([$current_year]);
        $gross_fixed_assets = $stmt_nca->fetchColumn() ?: 0;
        $accumulated_depreciation = calculate_accumulated_depreciation($current_year);
        $net_ppe = $gross_fixed_assets - $accumulated_depreciation;

        // 2. Financial Investments (from 'investments' table)
        $financial_investments = get_financial_investments_total($current_year);

        $non_current_assets = $net_ppe + $financial_investments;

        // 3. Current Assets
        $accounts_receivable = calculate_ar_balance($date_end);

        // LIABILITIES
        $accounts_payable = calculate_ap_balance($current_year);

        $stmt_tax_paid = $pdo->prepare("SELECT SUM(amount) FROM tax_payments WHERE financial_year = ?");
        $stmt_tax_paid->execute([$current_year]);
        $prepaid_taxes = $stmt_tax_paid->fetchColumn() ?: 0;

        $profit_before_tax = $summary_data[$current_year]['profit_before_tax'];
        $income_tax_expense = $summary_data[$current_year]['income_tax_expense'];

        // Tax Asset Logic (Loss Carryforward/Receivable)
        if ($profit_before_tax < 0 && $prepaid_taxes > 0) {
            // Loss Scenario: Tax Paid is an Asset
            $tax_payable = 0;
            $tax_receivable = $prepaid_taxes;
        } else {
            // Profit Scenario
            $tax_balance = $income_tax_expense - $prepaid_taxes;
            $tax_payable = ($tax_balance > 0) ? $tax_balance : 0;
            $tax_receivable = ($tax_balance < 0) ? abs($tax_balance) : 0;
        }

        // Loans
        $current_liabilities = $accounts_payable + $tax_payable;
        $non_current_liabilities = $bank_loans;
        $total_liabilities = $current_liabilities + $non_current_liabilities;

        // EQUITY
        $equity = $equity_data[$current_year]['closing_balance'];

        // PRELIMINARY BALANCE CHECK
        // Assets = Liabilities + Equity
        // Cash is the plug.
        // Total Liabilities & Equity (Target)
        $total_liabilities_and_equity = $total_liabilities + $equity;

        // Known Assets
        $other_current_assets = $accounts_receivable + $tax_receivable;

        // Cash Position Calculation
        $cash_position = $total_liabilities_and_equity - $non_current_assets - $other_current_assets;

        $shareholder_loan = 0;
        if ($cash_position < 0) {
            // Negative Cash is impossible. It implies Shareholder Loan / Short Term Borrowing.
            $shareholder_loan = abs($cash_position);
            $cash_position = 0;

            // Adjust Liabilities
            $current_liabilities += $shareholder_loan;
            $total_liabilities += $shareholder_loan;
            $total_liabilities_and_equity += $shareholder_loan; // Target increases to match new liability
        }

        $current_assets = $other_current_assets + $cash_position;
        $total_assets = $non_current_assets + $current_assets;

        $balance_data[$current_year] = [
            'current_assets' => $current_assets,
            'non_current_assets' => $non_current_assets,
            'net_ppe' => $net_ppe,
            'financial_investments' => $financial_investments,
            'total_assets' => $total_assets,
            'accounts_receivable' => $accounts_receivable,
            'accounts_payable' => $accounts_payable,
            'tax_payable' => $tax_payable,
            'tax_receivable' => $tax_receivable,
            'loans_payable' => $bank_loans,
            'shareholder_loan' => $shareholder_loan,
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
        $interest_expense = $summary_data[$current_year]['interest_expense'];

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

        // 2. Investing Activities
        $stmt_assets = $pdo->prepare("SELECT SUM(purchase_cost) FROM assets WHERE YEAR(purchase_date) = ?");
        $stmt_assets->execute([$current_year]);
        $assets_purchased = $stmt_assets->fetchColumn() ?: 0;

        // Note: Investments table handles financial investments
        $stmt_inv = $pdo->prepare("SELECT SUM(purchase_cost) FROM investments WHERE YEAR(purchase_date) = ?");
        $stmt_inv->execute([$current_year]);
        $investments_purchased = $stmt_inv->fetchColumn() ?: 0;

        $investing_activities = -($assets_purchased + $investments_purchased);

        // 3. Financing Activities
        $loans_end = $balance_sheet_data[$current_year]['loans_payable'];
        $loans_start = ($i == 0) ? $balance_sheet_data[$prev_year]['loans_payable'] : 0;
        $net_loans = $loans_end - $loans_start;

        // Shareholder loan change
        $sl_end = $balance_sheet_data[$current_year]['shareholder_loan'];
        $sl_start = ($i == 0) ? $balance_sheet_data[$prev_year]['shareholder_loan'] : 0;
        $net_sl = $sl_end - $sl_start;

        $financing_activities = $net_loans + $net_sl;

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
             // Correctly calculate previous net profits independently
             if (isset($all_summary_data[$y])) {
                 $opening_balance += $all_summary_data[$y]['net_profit_after_tax'];
             } else {
                 $opening_balance += calculate_annual_net_profit($y);
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
            u.exchange_rate,
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

// Add function to get Financial Investment Details for Note 7
function get_financial_investments_details($year) {
    global $pdo;
    // Fetch investments up to this year
    $stmt = $pdo->prepare("SELECT description, investment_type, quantity, purchase_cost FROM investments WHERE YEAR(purchase_date) <= ?");
    $stmt->execute([$year]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
