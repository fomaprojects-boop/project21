<?php
require_once __DIR__ . '/db.php';

// IFRS Logic Engine
// Purpose: Process raw transaction data into IFRS-compliant reporting structures
// (Income Statement, Balance Sheet, Cash Flow, Notes)

function get_complete_financial_data($year, $manual_inputs = []) {
    // 1. Get Base Data (TZS)
    $data = calculate_base_financials($year);

    // 2. Incorporate Manual Inputs (adjustments not in DB)
    $data = apply_manual_adjustments($data, $year, $manual_inputs);

    // 3. Currency Conversion (if needed)
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

    // We calculate for Target Year and Previous Year (Comparative)
    $years = [$target_year, $target_year - 1];
    $summary = [];
    $balance_sheet = [];
    $cash_flow = [];
    $equity = [];
    $ppe_movement = [];
    $investments = [];

    // Fetch Asset Details for Notes (Global)
    $investments = fetch_financial_investments();

    foreach ($years as $y) {
        // A. Income Statement Components
        $revenue = calculate_revenue($y);
        $expenses = calculate_expenses_breakdown($y); // Split COGS vs OPEX
        $depreciation_data = calculate_depreciation_and_ppe($y); // Returns [charge, net_book_value, movement_schedule]

        $cogs = $expenses['cogs_total'];
        $opex = $expenses['opex_total'];
        $depreciation_charge = $depreciation_data['total_charge'];

        // Interest is typically manual, but we place it here for structure
        $interest = 0; // Placeholder, populated by manual inputs later

        $gross_profit = $revenue - $cogs;
        $operating_profit = $gross_profit - $opex - $depreciation_charge;
        $profit_before_tax = $operating_profit - $interest;

        // Tax Calculation (Simplified 30% if profit > 0)
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

        // Tax Position
        // If Tax Expense > Paid, Liability. If Paid > Expense (or Loss), Asset.
        // For simplicity in this step, we assume Tax Payable = Tax Expense (unpaid at year end)
        // Adjust logic: Tax Payable accumulates.
        // Real logic: We need 'tax_paid' from DB.
        $tax_paid = calculate_tax_paid($y);
        $tax_liability_net = $tax_expense - $tax_paid;

        $tax_payable = ($tax_liability_net > 0) ? $tax_liability_net : 0;
        $tax_receivable = ($tax_liability_net < 0) ? abs($tax_liability_net) : 0;

        // PPE & Investments
        $net_ppe = $depreciation_data['net_book_value'];
        $financial_investments = calculate_investment_value($y); // Cost basis for FVTPL

        // Equity (Retained Earnings + Share Capital)
        // Retained Earnings = Sum of Net Profit for all years <= y
        $retained_earnings = calculate_retained_earnings_cumulative($y);
        $share_capital = 0; // Manual input later

        $total_assets = $net_ppe + $financial_investments + $ar + $cash + $tax_receivable;
        // Logic Check: If Cash is negative (Overdraft), it's a Liability
        $shareholder_loan = 0;
        if ($cash < 0) {
            $shareholder_loan = abs($cash); // Treat overdraft as Shareholder Loan/Injection
            $cash = 0;
            $total_assets = $net_ppe + $financial_investments + $ar + $cash + $tax_receivable;
        }

        $total_equity = $share_capital + $retained_earnings;
        $total_liabilities = $ap + $tax_payable + $shareholder_loan;

        $balance_sheet[$y] = [
            'net_ppe' => $net_ppe,
            'financial_investments' => $financial_investments,
            'accounts_receivable' => $ar,
            'tax_receivable' => $tax_receivable,
            'cash_position' => $cash,
            'total_assets' => $total_assets,
            'equity' => $total_equity, // Base equity before manual
            'share_capital' => $share_capital,
            'retained_earnings' => $retained_earnings,
            'accounts_payable' => $ap,
            'tax_payable' => $tax_payable,
            'shareholder_loan' => $shareholder_loan,
            'loans_payable' => 0, // Manual
            'total_liabilities_and_equity' => $total_equity + $total_liabilities
        ];

        // C. Cash Flow Components (Indirect Method)
        // Needs deltas from Y-1
        $cash_flow[$y] = [
            'profit_before_tax' => $profit_before_tax,
            'depreciation_add_back' => $depreciation_charge,
            'tax_paid' => $tax_paid,
            // Changes calculated in post-processing
            'increase_in_ar' => 0,
            'increase_in_ap' => 0,
            'additions_ppe' => $depreciation_data['additions'],
            'purchase_investments' => calculate_investment_purchases($y),
            'proceeds_shares' => 0, // Manual
            'proceeds_borrowings' => 0 // Manual
        ];
    }

    return [
        'summary_data' => $summary,
        'balance_sheet_data' => $balance_sheet,
        'cash_flow_data' => $cash_flow,
        'equity_data' => [], // Populated in step 2
        'ppe_movement' => $ppe_movement,
        'investment_details' => $investments
    ];
}

function apply_manual_adjustments($data, $target_year, $manual) {
    // Apply inputs for Target Year
    $y = $target_year;

    // 1. Income Statement Adjustments
    $data['summary_data'][$y]['interest_expense'] = $manual['interest_expense'];
    $data['summary_data'][$y]['profit_before_tax'] -= $manual['interest_expense'];
    // Recalculate Tax & Net Profit
    $pbt = $data['summary_data'][$y]['profit_before_tax'];
    $tax = ($pbt > 0) ? $pbt * 0.30 : 0;
    $data['summary_data'][$y]['income_tax_expense'] = $tax;
    $data['summary_data'][$y]['net_profit_after_tax'] = $pbt - $tax;

    // Update Retained Earnings for Balance Sheet
    // We assume prior years are correct. We only adjust current year RE.
    // New RE = Prev Year RE + New Net Profit
    $new_retained_earnings = $data['balance_sheet_data'][$y-1]['retained_earnings'] + $data['summary_data'][$y]['net_profit_after_tax'];
    $data['balance_sheet_data'][$y]['retained_earnings'] = $new_retained_earnings;

    // 2. Balance Sheet Adjustments
    $data['balance_sheet_data'][$y]['loans_payable'] = $manual['bank_loans'];

    // Calculate Share Capital
    $share_capital_value = $manual['number_of_shares'] * $manual['par_value'];

    // Calculate Net Cash Injection
    // + Share Capital (Inflow)
    // + Loans (Inflow)
    // - Interest Expense (Outflow - assuming paid)
    $cash_injection = $share_capital_value + $manual['bank_loans'] - $manual['interest_expense'];

    // Update Cash
    $original_cash = $data['balance_sheet_data'][$y]['cash_position'];
    $shareholder_loan = $data['balance_sheet_data'][$y]['shareholder_loan']; // This was covering overdraft

    // Reconstruct Real Cash (could be negative)
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
    $data['balance_sheet_data'][$y]['equity'] = $share_capital_value + $new_retained_earnings; // Update Total Equity

    // Recalculate Totals
    $bs = $data['balance_sheet_data'][$y];
    $data['balance_sheet_data'][$y]['total_assets'] = $bs['net_ppe'] + $bs['financial_investments'] + $bs['accounts_receivable'] + $bs['tax_receivable'] + $bs['cash_position'];
    $data['balance_sheet_data'][$y]['total_liabilities_and_equity'] = $bs['equity'] + $bs['accounts_payable'] + $bs['tax_payable'] + $bs['shareholder_loan'] + $bs['loans_payable'];

    // 3. Cash Flow Adjustments
    $cf = &$data['cash_flow_data'][$y];
    $cf_prev = $data['cash_flow_data'][$y-1];
    $bs_prev = $data['balance_sheet_data'][$y-1];

    $cf['profit_before_tax'] = $data['summary_data'][$y]['profit_before_tax'];
    // Changes in WC
    $cf['increase_in_ar'] = $data['balance_sheet_data'][$y]['accounts_receivable'] - $bs_prev['accounts_receivable'];
    $cf['increase_in_ap'] = $data['balance_sheet_data'][$y]['accounts_payable'] - $bs_prev['accounts_payable'];

    // Investing / Financing
    $cf['proceeds_shares'] = $share_capital_value; // Assuming all new for this year
    $cf['proceeds_borrowings'] = $manual['bank_loans']; // Assuming all new

    // Calculate Activities
    // Interest Paid usually Operating in IFRS or Financing. Let's deduct from Operating (as it reduced PBT).
    // Wait, PBT is already reduced by Interest. In Indirect Method, if Interest is non-cash (accrued), we add back.
    // If it is cash paid, we leave it (since PBT is lower).
    // BUT usually "Interest Paid" is shown separately in Operating or Financing.
    // Here: PBT is lower. Cash is lower. So it balances.

    $cf['operating_activities'] = $cf['profit_before_tax'] + $cf['depreciation_add_back'] - $cf['tax_paid'] - $cf['increase_in_ar'] + $cf['increase_in_ap'];
    $cf['investing_activities'] = -($cf['additions_ppe']) - ($cf['purchase_investments']);
    $cf['financing_activities'] = $cf['proceeds_shares'] + $cf['proceeds_borrowings'];

    $cf['net_increase_in_cash'] = $cf['operating_activities'] + $cf['investing_activities'] + $cf['financing_activities'];

    // Equity Data for PDF
    $data['equity_data'][$y] = [
        'share_capital' => $share_capital_value,
        'retained_earnings' => $new_retained_earnings
    ];
    // Fill prev year equity
    $data['equity_data'][$y-1] = [
        'share_capital' => 0, // Simplified
        'retained_earnings' => $data['balance_sheet_data'][$y-1]['retained_earnings']
    ];

    return $data;
}

// --- Helper Calculations ---

function calculate_revenue($year) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT SUM(total_amount) FROM invoices WHERE status != 'cancelled' AND YEAR(issue_date) = ?");
    $stmt->execute([$year]);
    return (float)$stmt->fetchColumn();
}

function calculate_expenses_breakdown($year) {
    global $pdo;
    $breakdown = ['cogs' => [], 'opex' => []];
    $cogs_total = 0;
    $opex_total = 0;

    // 1. Direct Expenses (Paid)
    // Claim = Approved. Requisition = Paid.
    // Use 'date' column instead of 'expense_date'
    $stmt = $pdo->prepare("
        SELECT expense_type, amount, 'direct' as source
        FROM direct_expenses
        WHERE YEAR(date) = ?
        AND (
            (type = 'Claim' AND status = 'Approved')
            OR
            (type = 'Requisition' AND status = 'Paid')
        )
    ");
    $stmt->execute([$year]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Payout Requests (Approved = Paid/Expense)
    $stmt2 = $pdo->prepare("
        SELECT service_type as expense_type, amount, 'payout' as source
        FROM payout_requests
        WHERE YEAR(processed_at) = ? AND status IN ('Approved', 'Paid')
    ");
    $stmt2->execute([$year]);
    $rows = array_merge($rows, $stmt2->fetchAll(PDO::FETCH_ASSOC));

    // 3. Payroll (Gross)
    // Query `payroll_entries` joined with `payroll_batches`
    $stmt3 = $pdo->prepare("
        SELECT 'Payroll' as expense_type, SUM(pe.basic_salary + pe.allowances) as amount
        FROM payroll_entries pe
        JOIN payroll_batches pb ON pe.batch_id = pb.id
        WHERE pb.status = 'Paid' AND pb.year = ?
        GROUP BY expense_type
    ");
    $stmt3->execute([$year]);
    $payroll = $stmt3->fetch(PDO::FETCH_ASSOC);
    if ($payroll) $rows[] = $payroll;

    foreach ($rows as $row) {
        $type = strtolower(trim($row['expense_type']));
        $amt = (float)$row['amount'];

        // COGS Classification
        if (in_array($type, ['materials', 'goods', 'production', 'goods_purchase', 'inventory'])) {
            $cogs_total += $amt;
            $breakdown['cogs'][$type] = ($breakdown['cogs'][$type] ?? 0) + $amt;
        } else {
            // Check for Investments (Asset) vs OPEX
            // 'shares', 'bonds', 'treasury_bills' are Assets, not Expenses
            if (in_array($type, ['shares', 'bonds', 'treasury_bills', 'machinery', 'equipment', 'vehicle', 'furniture', 'building'])) {
                continue; // Skip capitalization items
            }
            $opex_total += $amt;
            $breakdown['opex'][$type] = ($breakdown['opex'][$type] ?? 0) + $amt;
        }
    }

    return ['cogs_total' => $cogs_total, 'opex_total' => $opex_total, 'breakdown' => $breakdown];
}

function calculate_depreciation_and_ppe($year) {
    global $pdo;

    // Fetch depreciable assets (direct_expenses or payouts) or legacy assets
    $types = ['machinery', 'equipment', 'vehicle', 'furniture', 'building', 'computer'];
    $placeholders = implode(',', array_fill(0, count($types), '?'));

    // 1. Direct Expenses
    $sql = "SELECT id, expense_type, amount, date as date_acquired
            FROM direct_expenses
            WHERE status IN ('Approved', 'Paid')
            AND expense_type IN ($placeholders)
            AND YEAR(date) <= ?";

    // 2. Payout Requests
    $sql .= " UNION ALL
              SELECT id, service_type as expense_type, amount, processed_at as date_acquired
              FROM payout_requests
              WHERE status IN ('Approved', 'Paid')
              AND service_type IN ($placeholders)
              AND YEAR(processed_at) <= ?";

    // 3. Legacy Assets Table
    // Columns: name (mapped to expense_type), purchase_cost, purchase_date
    $sql .= " UNION ALL
              SELECT id, category as expense_type, purchase_cost as amount, purchase_date as date_acquired
              FROM assets
              WHERE YEAR(purchase_date) <= ?";

    // Bind Params (3 times: Direct, Payout, Assets)
    $params = array_merge($types, [$year], $types, [$year], [$year]);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_charge = 0;
    $total_cost = 0;
    $total_accum_dep = 0;
    $additions_year = 0;
    $movement = [];

    foreach ($assets as $asset) {
        $cost = (float)$asset['amount'];
        $acq_date = new DateTime($asset['date_acquired']);
        $acq_year = (int)$acq_date->format('Y');
        $type_lower = strtolower($asset['expense_type']);

        // Granular Rates
        $rate = 0.20; // Default (Other)
        if (strpos($type_lower, 'computer') !== false || strpos($type_lower, 'laptop') !== false) $rate = 0.375; // 37.5%
        if (strpos($type_lower, 'vehicle') !== false || strpos($type_lower, 'car') !== false) $rate = 0.25; // 25%
        if (strpos($type_lower, 'furniture') !== false) $rate = 0.125; // 12.5%
        if (strpos($type_lower, 'building') !== false) $rate = 0.05; // 5%

        // Calculate Accum Dep up to Start of Year
        // Logic: Iterate from purchase year up to (but not including) current year
        $accum_dep_opening = 0;

        for ($y_hist = $acq_year; $y_hist < $year; $y_hist++) {
             $months = 12;
             if ($y_hist == $acq_year) {
                 $months = 12 - (int)$acq_date->format('m') + 1; // Pro-rata for first year
             }
             $charge = ($cost * $rate * $months) / 12;
             $accum_dep_opening += $charge;
        }

        // Cap Accum Dep at Cost
        if ($accum_dep_opening > $cost) $accum_dep_opening = $cost;

        // Current Year Charge
        $charge_current = 0;
        if ($accum_dep_opening < $cost) {
            $months = 12;
            if ($acq_year == $year) {
                $months = 12 - (int)$acq_date->format('m') + 1; // Pro-rata
                $additions_year += $cost; // Purchased this year
            }
            $charge_current = ($cost * $rate * $months) / 12;

            // Cap at remaining value
            if (($accum_dep_opening + $charge_current) > $cost) {
                $charge_current = $cost - $accum_dep_opening;
            }
        }

        $total_charge += $charge_current;
        $total_cost += $cost;
        $total_accum_dep += ($accum_dep_opening + $charge_current);

        // Movement Data
        $cat = ucfirst($asset['expense_type']);
        if (!isset($movement[$cat])) {
            $movement[$cat] = ['opening_cost' => 0, 'additions' => 0, 'charge_for_year' => 0, 'closing_accum_dep' => 0, 'closing_nbv' => 0];
        }
        if ($acq_year < $year) $movement[$cat]['opening_cost'] += $cost;
        if ($acq_year == $year) $movement[$cat]['additions'] += $cost;

        $movement[$cat]['charge_for_year'] += $charge_current;
        $movement[$cat]['closing_accum_dep'] += ($accum_dep_opening + $charge_current);
        $movement[$cat]['closing_nbv'] += ($cost - ($accum_dep_opening + $charge_current));
    }

    return [
        'total_charge' => $total_charge,
        'net_book_value' => $total_cost - $total_accum_dep,
        'additions' => $additions_year,
        'movement' => $movement
    ];
}

function fetch_financial_investments() {
    global $pdo;
    // Use 'date' column instead of 'expense_date'
    // REKEBISHO: 'description' column does not exist. Use 'reference' for direct_expenses and 'transaction_reference' for payout_requests
    $stmt = $pdo->prepare("
        SELECT expense_type as investment_type, amount as purchase_cost, reference as description, date as expense_date
        FROM direct_expenses
        WHERE status IN ('Approved','Paid') AND expense_type IN ('shares', 'bonds', 'treasury_bills')
        UNION ALL
        SELECT service_type as investment_type, amount as purchase_cost, transaction_reference as description, processed_at as expense_date
        FROM payout_requests
        WHERE status IN ('Approved','Paid') AND service_type IN ('shares', 'bonds', 'treasury_bills')
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function calculate_investment_value($year) {
    global $pdo;
    // Sum of cost for investments bought <= year
    $types = ['shares', 'bonds', 'treasury_bills'];
    $placeholders = implode(',', array_fill(0, count($types), '?'));

    // Use 'date' column
    $sql = "SELECT SUM(amount) as amount FROM direct_expenses WHERE status IN ('Approved','Paid') AND expense_type IN ($placeholders) AND YEAR(date) <= ?
            UNION ALL
            SELECT SUM(amount) as amount FROM payout_requests WHERE status IN ('Approved','Paid') AND service_type IN ($placeholders) AND YEAR(processed_at) <= ?";

    $stmt = $pdo->prepare("SELECT SUM(amount) FROM ($sql) as sub");
    $params = array_merge($types, [$year], $types, [$year]);
    $stmt->execute($params);
    return (float)$stmt->fetchColumn();
}

function calculate_investment_purchases($year) {
    global $pdo;
    $types = ['shares', 'bonds', 'treasury_bills'];
    $placeholders = implode(',', array_fill(0, count($types), '?'));

    // Use 'date' column
    $sql = "SELECT SUM(amount) as amount FROM direct_expenses WHERE status IN ('Approved','Paid') AND expense_type IN ($placeholders) AND YEAR(date) = ?
            UNION ALL
            SELECT SUM(amount) as amount FROM payout_requests WHERE status IN ('Approved','Paid') AND service_type IN ($placeholders) AND YEAR(processed_at) = ?";

    $stmt = $pdo->prepare("SELECT SUM(amount) FROM ($sql) as sub");
    $params = array_merge($types, [$year], $types, [$year]);
    $stmt->execute($params);
    return (float)$stmt->fetchColumn();
}

function calculate_accounts_receivable($year) {
    global $pdo;
    // REFACTORED: Historical AR accuracy
    // Formula: Total Invoiced (<= year) - Total Paid (payment_date <= year)
    // This ensures payments made in future years don't clear old AR in historical reports.

    // 1. Total Invoiced up to year end
    $stmt1 = $pdo->prepare("
        SELECT SUM(total_amount)
        FROM invoices
        WHERE status != 'cancelled'
        AND YEAR(issue_date) <= ?
    ");
    $stmt1->execute([$year]);
    $total_invoiced = (float)$stmt1->fetchColumn();

    // 2. Total Paid up to year end
    // Assuming 'invoice_payments' table links to invoices
    // If invoice_payments doesn't exist, we fallback to 'amount_paid' on invoices but that's current state.
    // Let's assume invoice_payments exists as it's standard for this level of detail.
    // If not, we fall back to the naive approach but filtered by update time? No, unreliable.
    // Based on previous code analysis, 'invoice_payments' is used in 'calculate_cash_position', so it exists.

    $stmt2 = $pdo->prepare("
        SELECT SUM(amount)
        FROM invoice_payments
        WHERE YEAR(payment_date) <= ?
    ");
    $stmt2->execute([$year]);
    $total_paid = (float)$stmt2->fetchColumn();

    return max(0, $total_invoiced - $total_paid);
}

function calculate_accounts_payable($year) {
    global $pdo;
    // 1. Payout Requests: 'Submitted' only. (Approved = Paid/Expense)
    // CORRECTED: Use 'submitted_at' instead of 'created_at' as per DB schema
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM payout_requests WHERE status = 'Submitted' AND YEAR(submitted_at) <= ?");
    $stmt->execute([$year]);
    $ap = (float)$stmt->fetchColumn();

    // 2. Direct Expenses: 'Requisition' + 'Approved'. (Not Paid yet)
    // STRICT RULE: Use 'date' column for ALL reporting.
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
    // Cash = Total Inflow - Total Outflow
    // 1. Inflow: Invoice Payments
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM invoice_payments WHERE YEAR(payment_date) <= ?");
    $stmt->execute([$year]);
    $inflow = (float)$stmt->fetchColumn();

    // 2. Outflow: Expenses (Direct Claims Approved, Requisitions Paid, Payouts Approved/Paid, Payroll Paid, Tax Paid)
    $outflow = 0;

    // Direct Exp (Claims Approved + Req Paid). Use 'date' column.
    $stmt2 = $pdo->prepare("SELECT SUM(amount) FROM direct_expenses WHERE ((type='Claim' AND status='Approved') OR (type='Requisition' AND status='Paid')) AND YEAR(date) <= ?");
    $stmt2->execute([$year]);
    $outflow += (float)$stmt2->fetchColumn();

    // Payouts (Approved/Paid)
    $stmt3 = $pdo->prepare("SELECT SUM(amount) FROM payout_requests WHERE status IN ('Approved', 'Paid') AND YEAR(processed_at) <= ?");
    $stmt3->execute([$year]);
    $outflow += (float)$stmt3->fetchColumn();

    // Payroll - Corrected Query using payroll_entries + payroll_batches
    $stmt4 = $pdo->prepare("
        SELECT SUM(pe.net_salary)
        FROM payroll_entries pe
        JOIN payroll_batches pb ON pe.batch_id = pb.id
        WHERE pb.status = 'Paid' AND pb.year <= ?
    ");
    $stmt4->execute([$year]);
    $outflow += (float)$stmt4->fetchColumn();

    // Tax
    $outflow += calculate_tax_paid($year);

    return $inflow - $outflow;
}

function calculate_retained_earnings_cumulative($year) {
    // Retained Earnings = Sum of Net Profit for all years up to $year
    global $pdo;

    // Determine the start year dynamically (inception of data)
    $stmt = $pdo->query("SELECT MIN(YEAR(issue_date)) FROM invoices");
    $min_year = $stmt->fetchColumn();
    $start_year = $min_year ? (int)$min_year : 2020; // Default to 2020 if no data

    $total_retained = 0;

    for ($y = $start_year; $y <= $year; $y++) {
        $rev = calculate_revenue($y);
        $exp = calculate_expenses_breakdown($y);
        $dep = calculate_depreciation_and_ppe($y)['total_charge'];
        $pbt = ($rev - $exp['cogs_total']) - $exp['opex_total'] - $dep;
        // Assume 0 interest for history (manual inputs only affect current reporting year in this logic)
        $tax = ($pbt > 0) ? $pbt * 0.30 : 0;
        $net = $pbt - $tax;
        $total_retained += $net;
    }
    return $total_retained;
}

function convert_all_monetary_values($data, $rate) {
    array_walk_recursive($data, function(&$value, $key) use ($rate) {
        if (is_numeric($value) && !in_array($key, ['year', 'number_of_shares'])) {
            // Corrected Logic: Division for weaker -> stronger currency conversion
            // e.g. TZS -> USD: Value / Rate
             $value = $value / $rate;
        }
    });
    return $data;
}
?>
