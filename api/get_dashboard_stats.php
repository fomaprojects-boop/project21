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

function getMonthlyTaxData($pdo, $user_id, $type, $targetMonth, $targetYear) {
    if ($type === 'VAT') {
        $sql = "SELECT total_amount, tax_rate
                FROM invoices
                WHERE user_id = ?
                AND MONTH(issue_date) = ?
                AND YEAR(issue_date) = ?
                AND status = 'Paid'
                AND tax_rate > 0";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $targetMonth, $targetYear]);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = 0;
        foreach ($invoices as $inv) {
            $rate = floatval($inv['tax_rate']);
            $amt = floatval($inv['total_amount']);
            if ($rate > 0) {
                // VAT = Total - (Total / (1 + rate/100))
                $total += $amt - ($amt / (1 + ($rate / 100)));
            }
        }
        return $total;
    } elseif ($type === 'WHT') {
        // WHT logic: Sum of WHT from Payouts in that month
        $sql = "SELECT SUM(
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
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$targetMonth, $targetYear]);
        return $stmt->fetchColumn() ?: 0;
    }
    return 0;
}

try {
    $current_month = (int)date('m');
    $current_year = (int)date('Y');
    $today_day = (int)date('d');

    // 1. REVENUE (Strictly Current Month)
    $revenue_sql = "SELECT SUM(amount_paid)
                    FROM invoices
                    WHERE user_id = ?
                    AND status = 'Paid'
                    AND MONTH(issue_date) = ? AND YEAR(issue_date) = ?
                    AND document_type IN ('Invoice', 'Receipt', 'Estimate', 'Quotation', 'Tax Invoice', 'Proforma Invoice')";
    $stmt = $pdo->prepare($revenue_sql);
    $stmt->execute([$user_id, $current_month, $current_year]);
    $total_revenue = $stmt->fetchColumn() ?: 0;

    // 2. EXPENSES (Strictly Current Month)
    $expenses_total = 0;

    // Direct Expenses (Requisitions & Claims)
    $exp_sql = "SELECT SUM(amount) FROM direct_expenses
                WHERE user_id = ?
                AND (status = 'Paid' OR status = 'Approved' OR status = 'Posted to GL')
                AND MONTH(date) = ? AND YEAR(date) = ?";
    $stmt = $pdo->prepare($exp_sql);
    $stmt->execute([$user_id, $current_month, $current_year]);
    $expenses_total += ($stmt->fetchColumn() ?: 0);

    // Payouts (Vendor Payments)
    $payout_sql = "SELECT SUM(amount) FROM payout_requests
                   WHERE (status = 'Approved' OR status = 'Paid' OR status = 'Processed')
                   AND service_type NOT LIKE '%Asset%'
                   AND MONTH(submitted_at) = ? AND YEAR(submitted_at) = ?";
    $stmt = $pdo->prepare($payout_sql);
    $stmt->execute([$current_month, $current_year]);
    $expenses_total += ($stmt->fetchColumn() ?: 0);


    // 3. TAX LOGIC (Rolling)
    // Previous Month Calculation
    $prev_month = $current_month - 1;
    $prev_year = $current_year;
    if ($prev_month == 0) {
        $prev_month = 12;
        $prev_year = $current_year - 1;
    }

    // Check Payment Status of Previous Month
    $stmt = $pdo->prepare("SELECT is_paid FROM monthly_tax_status WHERE tax_type = ? AND month = ? AND year = ?");

    // --- VAT ---
    $stmt->execute(['VAT', $prev_month, $prev_year]);
    $vat_is_paid = $stmt->fetchColumn(); // Returns 1 or false

    $vat_amount = 0;
    $vat_status = '';
    $vat_days_overdue = 0;
    $vat_display_period = '';
    $vat_due_date = '';

    if ($vat_is_paid) {
        // Show Current Month (Accruing)
        $vat_amount = getMonthlyTaxData($pdo, $user_id, 'VAT', $current_month, $current_year);
        $vat_status = 'Accruing';
        $dateObj = DateTime::createFromFormat('!m', $current_month);
        $vat_display_period = $dateObj->format('M');
        // Due date is next month 20th
        $vat_due_date = date('Y-m-20', strtotime('+1 month'));
    } else {
        // Show Previous Month (Liability)
        $vat_amount = getMonthlyTaxData($pdo, $user_id, 'VAT', $prev_month, $prev_year);
        $dateObj = DateTime::createFromFormat('!m', $prev_month);
        $vat_display_period = $dateObj->format('M');
        // Due date is this month 20th
        $vat_due_date = date('Y-m-20');

        if ($today_day > 20) {
            $vat_status = 'Overdue';
            $vat_days_overdue = $today_day - 20;
        } else {
            $vat_status = 'Due';
        }
    }

    // --- WHT ---
    $stmt->execute(['WHT', $prev_month, $prev_year]);
    $wht_is_paid = $stmt->fetchColumn();

    $wht_amount = 0;
    $wht_status = '';
    $wht_days_overdue = 0;
    $wht_display_period = '';
    $wht_due_date = '';

    if ($wht_is_paid) {
        $wht_amount = getMonthlyTaxData($pdo, $user_id, 'WHT', $current_month, $current_year);
        $wht_status = 'Accruing';
        $dateObj = DateTime::createFromFormat('!m', $current_month);
        $wht_display_period = $dateObj->format('M');
        // Due date is next month 7th
        $wht_due_date = date('Y-m-07', strtotime('+1 month'));
    } else {
        $wht_amount = getMonthlyTaxData($pdo, $user_id, 'WHT', $prev_month, $prev_year);
        $dateObj = DateTime::createFromFormat('!m', $prev_month);
        $wht_display_period = $dateObj->format('M');
        // Due date is this month 7th
        $wht_due_date = date('Y-m-07');

        if ($today_day > 7) {
            $wht_status = 'Overdue';
            $wht_days_overdue = $today_day - 7;
        } else {
            $wht_status = 'Due';
        }
    }

    // --- STAMP DUTY ---
    $stamp_duty_sql = "SELECT SUM(amount * 0.01)
                       FROM payout_requests
                       WHERE service_type = 'Rent'
                       AND MONTH(submitted_at) = ?
                       AND YEAR(submitted_at) = ?
                       AND (status = 'Approved' OR status = 'Paid' OR status = 'Processed')";
    $stmt = $pdo->prepare($stamp_duty_sql);
    $stmt->execute([$current_month, $current_year]);
    $total_stamp_duty = $stmt->fetchColumn() ?: 0;


    // 4. INTELLIGENT INSIGHTS
    $insights = [];

    // A. Burn Rate (Expenses > Revenue + 10%)
    if ($total_revenue > 0) {
        $burn_ratio = ($expenses_total - $total_revenue) / $total_revenue;
        if ($burn_ratio > 0.10) {
            $insights[] = [
                'type' => 'warning',
                'message' => 'High Burn Rate: Expenses are ' . round($burn_ratio * 100) . '% higher than revenue.'
            ];
        }
    } elseif ($expenses_total > 0) {
         $insights[] = [
            'type' => 'warning',
            'message' => 'High Burn Rate: You have expenses but zero revenue this month.'
        ];
    }

    // B. Growth Indicator (MTD vs Last MTD)
    $last_month_revenue_sql = "SELECT SUM(amount_paid) FROM invoices
                               WHERE user_id = ? AND status = 'Paid'
                               AND issue_date BETWEEN ? AND ?";
    $last_month_start = date('Y-m-01', strtotime("last month"));
    $days_in_last_month = date('t', strtotime("last month"));
    $target_day = min($today_day, $days_in_last_month);
    $last_month_end = date('Y-m-', strtotime("last month")) . $target_day;

    $stmt = $pdo->prepare($last_month_revenue_sql);
    $stmt->execute([$user_id, $last_month_start, $last_month_end]);
    $last_month_revenue = $stmt->fetchColumn() ?: 0;

    if ($total_revenue > $last_month_revenue) {
        $insights[] = [
            'type' => 'success',
            'message' => 'Growth Trend: Revenue is up compared to last month (same period).'
        ];
    }

    // C. Collection Alert (Overdue Invoices Count > 5)
    $overdue_sql = "SELECT COUNT(*) FROM invoices
                    WHERE user_id = ?
                    AND status = 'Overdue'
                    AND due_date < CURRENT_DATE";
    $stmt = $pdo->prepare($overdue_sql);
    $stmt->execute([$user_id]);
    $overdue_count = $stmt->fetchColumn();

    if ($overdue_count > 5) {
        $insights[] = [
            'type' => 'danger',
            'message' => 'Collection Alert: ' . $overdue_count . ' invoices are overdue. Initiate debt collection.'
        ];
    }

    // D. Tax Compliance (Date >= 18 and VAT Unpaid)
    if ($today_day >= 18 && $vat_status !== 'Accruing' && !$vat_is_paid) {
        $insights[] = [
            'type' => 'danger',
            'message' => 'Critical Tax Deadline: VAT for ' . $vat_display_period . ' is due soon or overdue.'
        ];
    }

    if (empty($insights)) {
        $insights[] = [
            'type' => 'success',
            'message' => 'System is running smoothly. No critical actions needed.'
        ];
    }

    // 5. ACTIVITY
    $activity_sql = "
        (SELECT 'Invoice' as type, invoice_number as reference, total_amount as amount, created_at
         FROM invoices WHERE user_id = ?)
        UNION ALL
        (SELECT 'Expense' as type, expense_type as reference, amount, created_at
         FROM direct_expenses WHERE user_id = ?)
        ORDER BY created_at DESC LIMIT 5
    ";
    $stmt = $pdo->prepare($activity_sql);
    $stmt->execute([$user_id, $user_id]);
    $activity = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. CHART DATA
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
        if (array_sum($values) == 0) return 'neutral';
        $first = $values[0];
        $last = end($values);
        if ($last > $first) return 'bullish';
        if ($last < $first) return 'bearish';
        return 'neutral';
    }

    $week_data = getRevenueData($pdo, $user_id, date('Y-m-d', strtotime('-6 days')), date('Y-m-d'), 'day');
    $month_data = getRevenueData($pdo, $user_id, date('Y-m-d', strtotime('-29 days')), date('Y-m-d'), 'day');
    $three_months_data = getRevenueData($pdo, $user_id, date('Y-m-01', strtotime('-2 months')), date('Y-m-t'), 'month');
    $six_months_data = getRevenueData($pdo, $user_id, date('Y-m-01', strtotime('-5 months')), date('Y-m-t'), 'month');
    $year_data = getRevenueData($pdo, $user_id, date('Y-m-01', strtotime('-11 months')), date('Y-m-t'), 'month');

    $charts = [
        'week' => ['labels' => array_keys($week_data), 'data' => array_values($week_data), 'trend' => calculateTrend($week_data)],
        'month' => ['labels' => array_keys($month_data), 'data' => array_values($month_data), 'trend' => calculateTrend($month_data)],
        'three_months' => ['labels' => array_keys($three_months_data), 'data' => array_values($three_months_data), 'trend' => calculateTrend($three_months_data)],
        'six_months' => ['labels' => array_keys($six_months_data), 'data' => array_values($six_months_data), 'trend' => calculateTrend($six_months_data)],
        'year' => ['labels' => array_keys($year_data), 'data' => array_values($year_data), 'trend' => calculateTrend($year_data)]
    ];

    echo json_encode([
        'status' => 'success',
        'revenue' => $total_revenue,
        'expenses' => $expenses_total,
        'taxes' => [
            'vat' => [
                'amount' => $vat_amount,
                'status' => $vat_status,
                'overdue_days' => $vat_days_overdue,
                'period' => $vat_display_period,
                'due_date' => $vat_due_date,
                'is_paid' => $vat_is_paid
            ],
            'wht' => [
                'amount' => $wht_amount,
                'status' => $wht_status,
                'overdue_days' => $wht_days_overdue,
                'period' => $wht_display_period,
                'due_date' => $wht_due_date,
                'is_paid' => $wht_is_paid
            ],
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