<?php
// api/get_dashboard_stats.php

require_once 'config.php';
require_once 'db.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'User not logged in.']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Helper function to check tax status
function checkTaxStatus($pdo, $type, $month, $year, $dueDateDay) {
    // Calculate due date
    // If month is 12 (Dec), due month is 1 (Jan) of next year.
    $dueYear = ($month == 12) ? $year + 1 : $year;
    $dueMonth = ($month == 12) ? 1 : $month + 1;

    $dueDate = date("$dueYear-$dueMonth-$dueDateDay");
    $today = date('Y-m-d');

    $stmt = $pdo->prepare("SELECT is_paid, date_paid FROM monthly_tax_status WHERE tax_type = ? AND month = ? AND year = ?");
    $stmt->execute([$type, $month, $year]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $is_paid = $row ? (bool)$row['is_paid'] : false;

    $status = 'Pending';
    $overdue_days = 0;

    if ($is_paid) {
        $status = 'Paid';
    } elseif ($today > $dueDate) {
        $status = 'Overdue';
        $diff = strtotime($today) - strtotime($dueDate);
        $overdue_days = ceil($diff / (60 * 60 * 24));
    }

    return [
        'is_paid' => $is_paid,
        'status' => $status,
        'due_date' => $dueDate,
        'overdue_days' => $overdue_days
    ];
}

try {
    // 1. REVENUE
    // "paid invoices, receipts, estimates, quotation, tax invoice na proforma invoice.. zikiwa paid tu"
    // We assume all these are in 'invoices' table with 'document_type'.
    // Status must be 'Paid'.

    $revenue_sql = "SELECT SUM(amount_paid)
                    FROM invoices
                    WHERE user_id = ?
                    AND status = 'Paid'
                    AND document_type IN ('Invoice', 'Receipt', 'Estimate', 'Quotation', 'Tax Invoice', 'Proforma Invoice')";
    $stmt = $pdo->prepare($revenue_sql);
    $stmt->execute([$user_id]);
    $total_revenue = $stmt->fetchColumn() ?: 0;

    // 2. VAT (Monthly)
    // "kodi ya VAT ni ya mauzo ya mwezi huu" -> Sales (Invoices) of current month.
    // Check if they are paid? "nyaraka yeyote hapo iliyokuwa paid ... kama ilikuwa na VAT"

    $current_month = (int)date('m');
    $current_year = (int)date('Y');

    $vat_sql = "SELECT total_amount, tax_rate
                FROM invoices
                WHERE user_id = ?
                AND MONTH(issue_date) = ?
                AND YEAR(issue_date) = ?
                AND status = 'Paid'
                AND tax_rate > 0";
    $stmt = $pdo->prepare($vat_sql);
    $stmt->execute([$user_id, $current_month, $current_year]);
    $vat_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_vat_due = 0;
    foreach ($vat_invoices as $inv) {
        // Calculate VAT component from Total Amount (inclusive)
        // VAT = Total - (Total / (1 + rate/100))
        $rate = floatval($inv['tax_rate']);
        $total = floatval($inv['total_amount']);
        if ($rate > 0) {
            $vat_component = $total - ($total / (1 + ($rate / 100)));
            $total_vat_due += $vat_component;
        }
    }

    $vat_info = checkTaxStatus($pdo, 'VAT', $current_month, $current_year, 20);

    // 3. EXPENSES
    // Requisition: Approved AND Paid.
    // Claim: Approved (Only).
    // Payout Requests: Paid (Processed).
    // WHT from Vendor Payments.

    $expenses_total = 0;

    // Requisitions (Approved AND Paid)
    $req_sql = "SELECT SUM(amount) FROM direct_expenses
                WHERE user_id = ? AND type = 'requisition' AND status = 'Paid'";
    $stmt = $pdo->prepare($req_sql);
    $stmt->execute([$user_id]);
    $expenses_total += ($stmt->fetchColumn() ?: 0);

    // Claims (Approved - regardless of payment status, as they are liabilities/reimbursements)
    // Including 'Approved for Payment' for backward compatibility
    $claim_sql = "SELECT SUM(amount) FROM direct_expenses
                  WHERE user_id = ? AND type = 'claim' AND (status = 'Approved' OR status = 'Approved for Payment' OR status = 'Paid' OR status = 'Posted to GL')";
    $stmt = $pdo->prepare($claim_sql);
    $stmt->execute([$user_id]);
    $expenses_total += ($stmt->fetchColumn() ?: 0);

    // Vendor Payments (Payouts) - Exclude Asset Purchases
    // Assuming 'Asset Purchase' is a service type, or similar.
    $payout_sql = "SELECT SUM(amount) FROM payout_requests
                   WHERE (status = 'Approved' OR status = 'Paid' OR status = 'Processed')
                   AND service_type NOT LIKE '%Asset%'";
    $stmt = $pdo->prepare($payout_sql);
    $stmt->execute();
    $expenses_total += ($stmt->fetchColumn() ?: 0);

    // WHT Calculation (from Payouts this month)
    $wht_sql = "SELECT SUM(
                    amount * CASE 
                        WHEN service_type = 'Professional Service' THEN 0.05
                        WHEN service_type = 'Goods/Products' THEN 0.03
                        WHEN service_type = 'Rent' THEN 0.10
                        ELSE 0
                    END
                ) 
                FROM payout_requests
                WHERE MONTH(submitted_at) = ?
                AND YEAR(submitted_at) = ?
                AND (status = 'Approved' OR status = 'Paid' OR status = 'Processed')";
    $stmt = $pdo->prepare($wht_sql);
    $stmt->execute([$current_month, $current_year]);
    $total_wht_due = $stmt->fetchColumn() ?: 0;

    // Stamp Duty (Rent) Calculation (1% of Rent amount)
    $stamp_duty_sql = "SELECT SUM(amount * 0.01)
                       FROM payout_requests
                       WHERE service_type = 'Rent'
                       AND MONTH(submitted_at) = ?
                       AND YEAR(submitted_at) = ?
                       AND (status = 'Approved' OR status = 'Paid' OR status = 'Processed')";
    $stmt = $pdo->prepare($stamp_duty_sql);
    $stmt->execute([$current_month, $current_year]);
    $total_stamp_duty = $stmt->fetchColumn() ?: 0;

    // Add WHT to total expenses? Usually WHT is deducted from payment, but the expense is the Gross.
    // But user asked for "total expenses... onesha pia Withholding Taxes".
    // Usually Total Expense = Gross Amount.
    // If we summed 'amount' from payout_requests, check if that 'amount' is Gross or Net.
    // Usually 'amount' is the requested amount (Gross). WHT is a deduction.
    // So expenses_total already includes the WHT portion (as part of gross expense).
    // We will just display WHT separately as requested.

    $wht_info = checkTaxStatus($pdo, 'WHT', $current_month, $current_year, 7);

    // 4. RECENT ACTIVITY
    // "kitendo anachokifanya user aliyelogin"
    // We'll try to find logs. If no logs table, we synthesize from their actions (Created Invoice, Expense, etc).

    $activity = [];

    // Recent Invoices
    $stmt = $pdo->prepare("SELECT 'Created Invoice' as action, invoice_number as details, created_at FROM invoices WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$user_id]);
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) $activity[] = $row;

    // Recent Expenses
    $stmt = $pdo->prepare("SELECT 'Submitted Expense' as action, expense_type as details, created_at FROM direct_expenses WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$user_id]);
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) $activity[] = $row;

    // Recent Logins? (Optional, if we tracked it)

    // Sort by time and take top 3
    usort($activity, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    $activity = array_slice($activity, 0, 3);


    // 5. GRAPH DATA (Revenue Overview)
    // "week, mwezi, miezi 3, miezi 6 na mwaka 1... style ya trend bullish au bearish"

    function getRevenueData($pdo, $user_id, $startDate, $endDate, $groupBy = 'day') {
        $format = ($groupBy === 'day') ? '%Y-%m-%d' : '%Y-%m';
        $phpFormat = ($groupBy === 'day') ? 'Y-m-d' : 'Y-m';
        
        $sql = "SELECT DATE_FORMAT(issue_date, '$format') as period, SUM(amount_paid) as total
                FROM invoices
                WHERE user_id = ?
                AND status = 'Paid'
                AND issue_date BETWEEN ? AND ?
                GROUP BY period
                ORDER BY period ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $startDate, $endDate]);
        $dbData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Fill missing dates/months
        $filledData = [];
        $current = strtotime($startDate);
        $end = strtotime($endDate);
        $step = ($groupBy === 'day') ? '+1 day' : '+1 month';

        while ($current <= $end) {
            $key = date($phpFormat, $current);
            $filledData[$key] = isset($dbData[$key]) ? (float)$dbData[$key] : 0;
            $current = strtotime($step, $current);
        }

        return $filledData;
    }

    function calculateTrend($data) {
        if (count($data) < 2) return 'neutral';
        $values = array_values($data);
        
        // If all values are 0, it's neutral
        if (array_sum($values) == 0) return 'neutral';

        // Linear regression for better trend analysis
        $n = count($values);
        $sumX = 0;
        $sumY = 0;
        $sumXY = 0;
        $sumXX = 0;

        for ($i = 0; $i < $n; $i++) {
            $sumX += $i;
            $sumY += $values[$i];
            $sumXY += ($i * $values[$i]);
            $sumXX += ($i * $i);
        }
        
        $denominator = ($n * $sumXX - $sumX * $sumX);
        if ($denominator == 0) return 'neutral';

        $slope = ($n * $sumXY - $sumX * $sumY) / $denominator;

        // Use a small threshold to avoid floating point noise
        if ($slope > 0.01) return 'bullish';
        if ($slope < -0.01) return 'bearish';
        return 'neutral';
    }

    // Week (Last 7 days)
    $week_data = getRevenueData($pdo, $user_id, date('Y-m-d', strtotime('-6 days')), date('Y-m-d'), 'day');

    // Month (Last 30 days)
    $month_data = getRevenueData($pdo, $user_id, date('Y-m-d', strtotime('-29 days')), date('Y-m-d'), 'day');

    // 3 Months
    $three_months_data = getRevenueData($pdo, $user_id, date('Y-m-01', strtotime('-2 months')), date('Y-m-t'), 'month');

    // 6 Months
    $six_months_data = getRevenueData($pdo, $user_id, date('Y-m-01', strtotime('-5 months')), date('Y-m-t'), 'month');

    // 1 Year
    $year_data = getRevenueData($pdo, $user_id, date('Y-m-01', strtotime('-11 months')), date('Y-m-t'), 'month');

    $charts = [
        'week' => [
            'labels' => array_keys($week_data),
            'data' => array_values($week_data),
            'trend' => calculateTrend($week_data)
        ],
        'month' => [
            'labels' => array_keys($month_data),
            'data' => array_values($month_data),
            'trend' => calculateTrend($month_data)
        ],
        'three_months' => [
            'labels' => array_keys($three_months_data),
            'data' => array_values($three_months_data),
            'trend' => calculateTrend($three_months_data)
        ],
        'six_months' => [
            'labels' => array_keys($six_months_data),
            'data' => array_values($six_months_data),
            'trend' => calculateTrend($six_months_data)
        ],
        'year' => [
            'labels' => array_keys($year_data),
            'data' => array_values($year_data),
            'trend' => calculateTrend($year_data)
        ]
    ];

    // 6. INTELLIGENT ANALYSIS (Insights)
    $insights = [];

    // Revenue Insight
    $current_month_rev = end($month_data); // Approx current month or last day
    // A better revenue comparison: This month total vs Last month total
    $this_month_total = $year_data[date('Y-m')] ?? 0;
    $last_month_total = $year_data[date('Y-m', strtotime('-1 month'))] ?? 0;

    if ($this_month_total > $last_month_total) {
        $diff = $this_month_total - $last_month_total;
        $insights[] = [
            'type' => 'success',
            'message' => 'Revenue is up by ' . number_format($diff) . ' compared to last month. Good job!'
        ];
    } elseif ($this_month_total < $last_month_total) {
        $insights[] = [
            'type' => 'warning',
            'message' => 'Revenue is lower than last month. Consider following up on pending estimates.'
        ];
    }

    // Expense Insight
    if ($expenses_total > $total_revenue && $total_revenue > 0) {
        $insights[] = [
            'type' => 'danger',
            'message' => 'High burn rate detected. Expenses exceed revenue. Review your spending.'
        ];
    }

    // Tax Insight
    if ($vat_info['status'] === 'Overdue' || $wht_info['status'] === 'Overdue') {
        $insights[] = [
            'type' => 'danger',
            'message' => 'You have overdue tax payments. Pay immediately to avoid penalties from TRA.'
        ];
    }

    // Default Insight if empty
    if (empty($insights)) {
        $insights[] = [
            'type' => 'success',
            'message' => 'System is running smoothly. No urgent issues detected.'
        ];
    }

    echo json_encode([
        'status' => 'success',
        'revenue' => $total_revenue,
        'expenses' => $expenses_total,
        'taxes' => [
            'vat' => array_merge(['amount' => $total_vat_due], $vat_info),
            'wht' => array_merge(['amount' => $total_wht_due], $wht_info),
            'stamp_duty' => $total_stamp_duty
        ],
        'activity' => $activity,
        'charts' => $charts,
        'insights' => $insights
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
