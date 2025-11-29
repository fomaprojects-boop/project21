<?php
require_once __DIR__ . '/db.php';

// IFRS Logic Engine (TRA Compliance: Cash Basis & VAT Separation)

function get_complete_financial_data($year, $manual_inputs = []) {
    // 1. Get Base Data
    $data = calculate_base_financials($year);
    
    // 2. Incorporate Manual Inputs
    $data = apply_manual_adjustments($data, $year, $manual_inputs);

    // 3. Currency Conversion
    $settings = get_settings();
    $target_currency = $settings['default_currency'] ?? 'TZS';
    $rate = $settings['exchange_rate'] ?? 1.0;
    
    if ($target_currency !== 'TZS' && $rate > 0 && $rate != 1.0) {
        $data = convert_all_monetary_values($data, $rate);
    }
    
    return $data;
}

function calculate_base_financials($target_year) {
    global $pdo;
    
    $years = [$target_year, $target_year - 1];
    $summary = [];
    $balance_sheet = [];
    $cash_flow = [];
    $ppe_movement = [];
    $investments = fetch_financial_investments(); 

    foreach ($years as $y) {
        // A. Income Statement Components
        // Revenue is now Net of VAT (Total Paid - VAT Component)
        $revenue = calculate_net_revenue($y); 
        $expenses = calculate_expenses_breakdown($y);
        $depreciation_data = calculate_depreciation_and_ppe($y);

        $cogs = $expenses['cogs_total'];
        $opex = $expenses['opex_total'];
        $depreciation_charge = $depreciation_data['total_charge'];
        
        $interest = 0; 

        $gross_profit = $revenue - $cogs;
        $operating_profit = $gross_profit - $opex - $depreciation_charge;
        $profit_before_tax = $operating_profit - $interest;
        
        // Tax Calculation
        $tax_expense = ($profit_before_tax > 0) ? $profit_before_tax * 0.30 : 0;
        $net_profit = $profit_before_tax - $tax_expense;

        $summary[$y] = [
            'total_revenue' => $revenue,
            'cogs' => $cogs,
            'gross_profit' => $gross_profit,
            'opex' => $opex,
            'total_depreciation' => $depreciation_charge,
            'operating_profit' => $operating_profit,
            'interest_expense' => $interest,
            'profit_before_tax' => $profit_before_tax,
            'income_tax_expense' => $tax_expense,
            'net_profit_after_tax' => $net_profit,
            'expense_breakdown' => $expenses['breakdown']
        ];
        
        $ppe_movement[$y] = $depreciation_data['movement'];

        // B. Balance Sheet Components
        $ar = calculate_accounts_receivable($y);
        $ap = calculate_accounts_payable($y);
        $cash = calculate_cash_position($y);
        
        $tax_paid = calculate_tax_paid($y);
        $tax_liability_net = $tax_expense - $tax_paid;
        
        $tax_payable = ($tax_liability_net > 0) ? $tax_liability_net : 0;
        $tax_receivable = ($tax_liability_net < 0) ? abs($tax_liability_net) : 0;

        $net_ppe = $depreciation_data['net_book_value'];
        $financial_investments = calculate_investment_value($y);

        $retained_earnings = calculate_retained_earnings_cumulative($y);
        $share_capital = 0;

        // Deferred Income Logic:
        // We have AR (Unpaid Invoices). Since Revenue is Cash Basis, this AR is not yet Income.
        // We record it as a Liability to balance the Asset side.
        $deferred_income = $ar; 

        $total_assets = $net_ppe + $financial_investments + $ar + $cash + $tax_receivable;
        
        // Negative Cash Handling
        $shareholder_loan = 0;
        if ($cash < 0) {
            $shareholder_loan = abs($cash);
            $cash = 0;
            $total_assets = $net_ppe + $financial_investments + $ar + $cash + $tax_receivable;
        }

        $total_equity = $share_capital + $retained_earnings;
        $total_liabilities = $ap + $tax_payable + $shareholder_loan + $deferred_income; 
        
        $balance_sheet[$y] = [
            'net_ppe' => $net_ppe,
            'financial_investments' => $financial_investments,
            'accounts_receivable' => $ar,
            'tax_receivable' => $tax_receivable,
            'cash_position' => $cash,
            'total_assets' => $total_assets,
            'equity' => $total_equity,
            'share_capital' => $share_capital,
            'retained_earnings' => $retained_earnings,
            'accounts_payable' => $ap,
            'tax_payable' => $tax_payable,
            'shareholder_loan' => $shareholder_loan,
            'deferred_income' => $deferred_income,
            'loans_payable' => 0,
            'total_liabilities_and_equity' => $total_equity + $total_liabilities
        ];

        // C. Cash Flow Components
        $cash_flow[$y] = [
            'profit_before_tax' => $profit_before_tax,
            'depreciation_add_back' => $depreciation_charge,
            'tax_paid' => $tax_paid,
            'increase_in_ar' => 0, 
            'increase_in_ap' => 0, 
            'additions_ppe' => $depreciation_data['additions'],
            'purchase_investments' => calculate_investment_purchases($y),
            'proceeds_shares' => 0,
            'proceeds_borrowings' => 0
        ];
    }

    return [
        'summary_data' => $summary,
        'balance_sheet_data' => $balance_sheet,
        'cash_flow_data' => $cash_flow,
        'equity_data' => [],
        'ppe_movement' => $ppe_movement,
        'investment_details' => $investments
    ];
}

function apply_manual_adjustments($data, $target_year, $manual) {
    $y = $target_year;
    
    // 1. Income Statement Adjustments
    $data['summary_data'][$y]['interest_expense'] = $manual['interest_expense'];
    $data['summary_data'][$y]['profit_before_tax'] -= $manual['interest_expense'];
    
    $pbt = $data['summary_data'][$y]['profit_before_tax'];
    $tax = ($pbt > 0) ? $pbt * 0.30 : 0;
    $data['summary_data'][$y]['income_tax_expense'] = $tax;
    $data['summary_data'][$y]['net_profit_after_tax'] = $pbt - $tax;
    
    // Update Retained Earnings
    $new_retained_earnings = $data['balance_sheet_data'][$y-1]['retained_earnings'] + $data['summary_data'][$y]['net_profit_after_tax'];
    $data['balance_sheet_data'][$y]['retained_earnings'] = $new_retained_earnings;
    
    // 2. Balance Sheet Adjustments
    $data['balance_sheet_data'][$y]['loans_payable'] = $manual['bank_loans'];
    
    $share_capital_value = $manual['number_of_shares'] * $manual['par_value'];
    $cash_injection = $share_capital_value + $manual['bank_loans'] - $manual['interest_expense'];
    
    $original_cash = $data['balance_sheet_data'][$y]['cash_position'];
    $shareholder_loan = $data['balance_sheet_data'][$y]['shareholder_loan'];
    
    $real_cash = ($original_cash > 0 ? $original_cash : -$shareholder_loan);
    $final_cash = $real_cash + $cash_injection;
    
    if ($final_cash >= 0) {
        $data['balance_sheet_data'][$y]['cash_position'] = $final_cash;
        $data['balance_sheet_data'][$y]['shareholder_loan'] = 0;
    } else {
        $data['balance_sheet_data'][$y]['cash_position'] = 0;
        $data['balance_sheet_data'][$y]['shareholder_loan'] = abs($final_cash);
    }
    
    $data['balance_sheet_data'][$y]['share_capital'] = $share_capital_value;
    $data['balance_sheet_data'][$y]['equity'] = $share_capital_value + $new_retained_earnings;

    $bs = $data['balance_sheet_data'][$y];
    $data['balance_sheet_data'][$y]['total_assets'] = $bs['net_ppe'] + $bs['financial_investments'] + $bs['accounts_receivable'] + $bs['tax_receivable'] + $bs['cash_position'];
    $data['balance_sheet_data'][$y]['total_liabilities_and_equity'] = $bs['equity'] + $bs['accounts_payable'] + $bs['tax_payable'] + $bs['shareholder_loan'] + $bs['loans_payable'] + $bs['deferred_income'];

    // 3. Cash Flow Adjustments
    $cf = &$data['cash_flow_data'][$y];
    $cf_prev = $data['cash_flow_data'][$y-1];
    $bs_prev = $data['balance_sheet_data'][$y-1];
    
    $cf['profit_before_tax'] = $data['summary_data'][$y]['profit_before_tax'];
    $cf['increase_in_ap'] = $data['balance_sheet_data'][$y]['accounts_payable'] - $bs_prev['accounts_payable'];
    $cf['proceeds_shares'] = $share_capital_value;
    $cf['proceeds_borrowings'] = $manual['bank_loans'];
    
    $cf['operating_activities'] = $cf['profit_before_tax'] + $cf['depreciation_add_back'] - $cf['tax_paid'] + $cf['increase_in_ap'];
    $cf['investing_activities'] = -($cf['additions_ppe']) - ($cf['purchase_investments']);
    $cf['financing_activities'] = $cf['proceeds_shares'] + $cf['proceeds_borrowings'];
    $cf['net_increase_in_cash'] = $cf['operating_activities'] + $cf['investing_activities'] + $cf['financing_activities'];

    $data['equity_data'][$y] = [
        'share_capital' => $share_capital_value,
        'retained_earnings' => $new_retained_earnings
    ];
    $data['equity_data'][$y-1] = [
        'share_capital' => 0,
        'retained_earnings' => $data['balance_sheet_data'][$y-1]['retained_earnings']
    ];

    return $data;
}

// --- Helper Calculations ---

// NEW: Calculate Net Revenue (Paid Amounts - VAT)
function calculate_net_revenue($year) {
    global $pdo;
    // Formula: SUM( PaidAmount / (1 + (TaxRate / 100)) )
    // This extracts the Net Revenue from the Gross Payment.
    // Using COALESCE to handle null tax_rates as 0%.
    // Using 'invoices' table to capture all payments (legacy and new).
    $sql = "SELECT SUM(amount_paid / (1 + (COALESCE(tax_rate, 0) / 100))) 
            FROM invoices 
            WHERE status != 'cancelled' 
            AND amount_paid > 0 
            AND YEAR(issue_date) = ?";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$year]);
    return (float)$stmt->fetchColumn();
}

function calculate_expenses_breakdown($year) {
    global $pdo;
    $breakdown = ['cogs' => [], 'opex' => []];
    $cogs_total = 0;
    $opex_total = 0;

    $stmt = $pdo->prepare("SELECT expense_type, amount FROM direct_expenses WHERE YEAR(date) = ? AND ((type = 'Claim' AND status = 'Approved') OR (type = 'Requisition' AND status = 'Paid'))");
    $stmt->execute([$year]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt2 = $pdo->prepare("SELECT service_type as expense_type, amount FROM payout_requests WHERE YEAR(processed_at) = ? AND status IN ('Approved', 'Paid')");
    $stmt2->execute([$year]);
    $rows = array_merge($rows, $stmt2->fetchAll(PDO::FETCH_ASSOC));

    $stmt3 = $pdo->prepare("SELECT 'Payroll' as expense_type, SUM(pe.basic_salary + pe.allowances) as amount FROM payroll_entries pe JOIN payroll_batches pb ON pe.batch_id = pb.id WHERE pb.status = 'Paid' AND pb.year = ? GROUP BY expense_type");
    $stmt3->execute([$year]);
    $payroll = $stmt3->fetch(PDO::FETCH_ASSOC);
    if ($payroll) $rows[] = $payroll;

    foreach ($rows as $row) {
        $type = strtolower(trim($row['expense_type']));
        $amt = (float)$row['amount'];
        if (in_array($type, ['materials', 'goods', 'production', 'goods_purchase', 'inventory']) || strpos($type, 'material') !== false) {
            $cogs_total += $amt;
            $breakdown['cogs'][$type] = ($breakdown['cogs'][$type] ?? 0) + $amt;
        } else {
            if (in_array($type, ['shares', 'bonds', 'treasury_bills', 'machinery', 'equipment', 'vehicle', 'furniture', 'building'])) continue; 
            $opex_total += $amt;
            $breakdown['opex'][$type] = ($breakdown['opex'][$type] ?? 0) + $amt;
        }
    }
    return ['cogs_total' => $cogs_total, 'opex_total' => $opex_total, 'breakdown' => $breakdown];
}

function calculate_depreciation_and_ppe($year) {
    global $pdo;
    $types = ['machinery', 'equipment', 'vehicle', 'furniture', 'building', 'computer'];
    $placeholders = implode(',', array_fill(0, count($types), '?'));
    
    $sql = "SELECT id, expense_type, amount, date as date_acquired FROM direct_expenses WHERE status IN ('Approved', 'Paid') AND expense_type IN ($placeholders) AND YEAR(date) <= ?
            UNION ALL 
            SELECT id, service_type as expense_type, amount, processed_at as date_acquired FROM payout_requests WHERE status IN ('Approved', 'Paid') AND service_type IN ($placeholders) AND YEAR(processed_at) <= ?
            UNION ALL
            SELECT id, category as expense_type, purchase_cost as amount, purchase_date as date_acquired FROM assets WHERE YEAR(purchase_date) <= ?";
    $params = array_merge($types, [$year], $types, [$year], [$year]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_charge = 0; $total_cost = 0; $total_accum_dep = 0; $additions_year = 0; $movement = [];
    foreach ($assets as $asset) {
        $cost = (float)$asset['amount'];
        $acq_date = new DateTime($asset['date_acquired']);
        $acq_year = (int)$acq_date->format('Y');
        $type_lower = strtolower($asset['expense_type']);
        $rate = (strpos($type_lower, 'computer') !== false) ? 0.375 : ((strpos($type_lower, 'vehicle') !== false) ? 0.25 : ((strpos($type_lower, 'furniture') !== false) ? 0.125 : ((strpos($type_lower, 'building') !== false) ? 0.05 : 0.20)));

        $accum_dep_opening = 0;
        for ($y_hist = $acq_year; $y_hist < $year; $y_hist++) {
             $months = ($y_hist == $acq_year) ? (12 - (int)$acq_date->format('m') + 1) : 12;
             $charge = ($cost * $rate * $months) / 12;
             $accum_dep_opening += $charge;
        }
        if ($accum_dep_opening > $cost) $accum_dep_opening = $cost;

        $charge_current = 0;
        if ($accum_dep_opening < $cost) {
            $months = 12;
            if ($acq_year == $year) {
                $months = 12 - (int)$acq_date->format('m') + 1; 
                $additions_year += $cost; 
            }
            $charge_current = ($cost * $rate * $months) / 12;
            if (($accum_dep_opening + $charge_current) > $cost) $charge_current = $cost - $accum_dep_opening;
        }
        $total_charge += $charge_current;
        $total_cost += $cost;
        $total_accum_dep += ($accum_dep_opening + $charge_current);
        
        $cat = ucfirst($asset['expense_type']);
        if (!isset($movement[$cat])) $movement[$cat] = ['opening_cost' => 0, 'additions' => 0, 'charge_for_year' => 0, 'closing_accum_dep' => 0, 'closing_nbv' => 0];
        if ($acq_year < $year) $movement[$cat]['opening_cost'] += $cost;
        if ($acq_year == $year) $movement[$cat]['additions'] += $cost;
        $movement[$cat]['charge_for_year'] += $charge_current;
        $movement[$cat]['closing_accum_dep'] += ($accum_dep_opening + $charge_current);
        $movement[$cat]['closing_nbv'] += ($cost - ($accum_dep_opening + $charge_current));
    }
    return ['total_charge' => $total_charge, 'net_book_value' => $total_cost - $total_accum_dep, 'additions' => $additions_year, 'movement' => $movement];
}

function fetch_financial_investments() {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT expense_type as investment_type, amount as purchase_cost, reference as description, date as expense_date 
        FROM direct_expenses WHERE status IN ('Approved','Paid') AND expense_type IN ('shares', 'bonds', 'treasury_bills')
        UNION ALL
        SELECT service_type as investment_type, amount as purchase_cost, transaction_reference as description, processed_at as expense_date
        FROM payout_requests WHERE status IN ('Approved','Paid') AND service_type IN ('shares', 'bonds', 'treasury_bills')
        UNION ALL
        SELECT investment_type, purchase_cost, description, purchase_date as expense_date FROM investments
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function calculate_investment_value($year) {
    global $pdo;
    $types = ['shares', 'bonds', 'treasury_bills'];
    $placeholders = implode(',', array_fill(0, count($types), '?'));
    $sql = "SELECT SUM(amount) as amount FROM direct_expenses WHERE status IN ('Approved','Paid') AND expense_type IN ($placeholders) AND YEAR(date) <= ?
            UNION ALL
            SELECT SUM(amount) as amount FROM payout_requests WHERE status IN ('Approved','Paid') AND service_type IN ($placeholders) AND YEAR(processed_at) <= ?
            UNION ALL
            SELECT SUM(purchase_cost) as amount FROM investments WHERE YEAR(purchase_date) <= ?";
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM ($sql) as sub");
    $params = array_merge($types, [$year], $types, [$year], [$year]);
    $stmt->execute($params);
    return (float)$stmt->fetchColumn();
}

function calculate_investment_purchases($year) {
    global $pdo;
    $types = ['shares', 'bonds', 'treasury_bills'];
    $placeholders = implode(',', array_fill(0, count($types), '?'));
    $sql = "SELECT SUM(amount) as amount FROM direct_expenses WHERE status IN ('Approved','Paid') AND expense_type IN ($placeholders) AND YEAR(date) = ?
            UNION ALL
            SELECT SUM(amount) as amount FROM payout_requests WHERE status IN ('Approved','Paid') AND service_type IN ($placeholders) AND YEAR(processed_at) = ?
            UNION ALL
            SELECT SUM(purchase_cost) as amount FROM investments WHERE YEAR(purchase_date) = ?";
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM ($sql) as sub");
    $params = array_merge($types, [$year], $types, [$year], [$year]);
    $stmt->execute($params);
    return (float)$stmt->fetchColumn();
}

function calculate_accounts_receivable($year) {
    global $pdo;
    $stmt1 = $pdo->prepare("SELECT SUM(total_amount) FROM invoices WHERE status != 'cancelled' AND YEAR(issue_date) <= ?");
    $stmt1->execute([$year]);
    $total_invoiced = (float)$stmt1->fetchColumn();
    
    $stmt2 = $pdo->prepare("SELECT SUM(amount_paid) FROM invoices WHERE YEAR(issue_date) <= ?");
    $stmt2->execute([$year]);
    $total_paid = (float)$stmt2->fetchColumn();
    
    return max(0, $total_invoiced - $total_paid);
}

function calculate_accounts_payable($year) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM payout_requests WHERE status = 'Submitted' AND YEAR(submitted_at) <= ?");
    $stmt->execute([$year]);
    $ap = (float)$stmt->fetchColumn();
    $stmt2 = $pdo->prepare("SELECT SUM(amount) FROM direct_expenses WHERE type = 'Requisition' AND status = 'Approved' AND YEAR(date) <= ?");
    $stmt2->execute([$year]);
    $ap += (float)$stmt2->fetchColumn();
    return $ap;
}

function calculate_tax_paid($year) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM tax_payments WHERE YEAR(payment_date) = ?");
    $stmt->execute([$year]);
    return (float)$stmt->fetchColumn();
}

function calculate_cash_position($year) {
    global $pdo;
    // Cash Inflow: GROSS Amount Paid (Incl. VAT) from invoices
    $stmt = $pdo->prepare("SELECT SUM(amount_paid) FROM invoices WHERE amount_paid > 0 AND YEAR(issue_date) <= ?");
    $stmt->execute([$year]);
    $inflow = (float)$stmt->fetchColumn();
    
    $outflow = 0;
    $stmt2 = $pdo->prepare("SELECT SUM(amount) FROM direct_expenses WHERE ((type='Claim' AND status='Approved') OR (type='Requisition' AND status='Paid')) AND YEAR(date) <= ?");
    $stmt2->execute([$year]);
    $outflow += (float)$stmt2->fetchColumn();
    
    $stmt3 = $pdo->prepare("SELECT SUM(amount) FROM payout_requests WHERE status IN ('Approved', 'Paid') AND YEAR(processed_at) <= ?");
    $stmt3->execute([$year]);
    $outflow += (float)$stmt3->fetchColumn();
    
    $stmt4 = $pdo->prepare("SELECT SUM(pe.net_salary) FROM payroll_entries pe JOIN payroll_batches pb ON pe.batch_id = pb.id WHERE pb.status = 'Paid' AND pb.year <= ?");
    $stmt4->execute([$year]);
    $outflow += (float)$stmt4->fetchColumn();
    
    $outflow += calculate_investment_purchases_cumulative($year);
    $outflow += calculate_tax_paid($year);
    
    return $inflow - $outflow;
}

function calculate_investment_purchases_cumulative($year) {
    global $pdo;
    $types = ['shares', 'bonds', 'treasury_bills'];
    $placeholders = implode(',', array_fill(0, count($types), '?'));
    $sql = "SELECT SUM(amount) as amount FROM direct_expenses WHERE status IN ('Approved','Paid') AND expense_type IN ($placeholders) AND YEAR(date) <= ?
            UNION ALL
            SELECT SUM(amount) as amount FROM payout_requests WHERE status IN ('Approved','Paid') AND service_type IN ($placeholders) AND YEAR(processed_at) <= ?
            UNION ALL
            SELECT SUM(purchase_cost) as amount FROM investments WHERE YEAR(purchase_date) <= ?";
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM ($sql) as sub");
    $params = array_merge($types, [$year], $types, [$year], [$year]);
    $stmt->execute($params);
    return (float)$stmt->fetchColumn();
}

function calculate_retained_earnings_cumulative($year) {
    global $pdo;
    $stmt = $pdo->query("SELECT MIN(YEAR(issue_date)) FROM invoices");
    $min_year = $stmt->fetchColumn();
    $start_year = $min_year ? (int)$min_year : 2020; 
    $total_retained = 0;
    for ($y = $start_year; $y <= $year; $y++) {
        $rev = calculate_net_revenue($y); // Use Net Revenue
        $exp = calculate_expenses_breakdown($y);
        $dep = calculate_depreciation_and_ppe($y)['total_charge'];
        $pbt = ($rev - $exp['cogs_total']) - $exp['opex_total'] - $dep;
        $tax = ($pbt > 0) ? $pbt * 0.30 : 0;
        $net = $pbt - $tax;
        $total_retained += $net;
    }
    return $total_retained;
}

function convert_all_monetary_values($data, $rate) {
    array_walk_recursive($data, function(&$value, $key) use ($rate) {
        if (is_numeric($value) && !in_array($key, ['year', 'number_of_shares'])) { 
             $value = $value / $rate;
        }
    });
    return $data;
}

function get_settings() {
    global $pdo;
    if (session_status() == PHP_SESSION_NONE) session_start();
    $user_id = $_SESSION['user_id'] ?? null;
    if ($user_id) {
        $stmt = $pdo->prepare("SELECT * FROM settings WHERE user_id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$settings) {
             $stmt = $pdo->query("SELECT * FROM settings LIMIT 1");
             $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        return $settings ?: [];
    }
    return [];
}
?>