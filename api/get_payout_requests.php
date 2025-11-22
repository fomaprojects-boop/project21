<?php
// api/get_payout_requests.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit();
}

require_once 'db.php';

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 5;
$offset = ($page - 1) * $limit;
$status = isset($_GET['status']) ? $_GET['status'] : 'All';

try {
    $where_clause = '';
    $params = [];

    if ($status !== 'All') {
        $where_clause = 'WHERE pr.status = :status';
        $params[':status'] = $status;
    }

    // Get total count
    $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM payout_requests pr " . $where_clause);
    $total_stmt->execute($params);
    $total_results = $total_stmt->fetchColumn();

    // Get default currency from settings
    $stmt_settings = $pdo->query("SELECT default_currency FROM settings WHERE id = 1");
    $settings = $stmt_settings->fetch(PDO::FETCH_ASSOC);
    $default_currency = ($settings && !empty($settings['default_currency'])) ? $settings['default_currency'] : 'TZS';

    $stmt = $pdo->prepare(
        "SELECT 
            pr.id, 
            v.full_name AS vendor_name, 
            pr.service_type, 
            pr.amount,
            pr.transaction_reference,
            pr.payment_method,
            pr.bank_name,
            pr.account_name,
            pr.account_number,
            pr.mobile_network,
            pr.mobile_phone,
            pr.invoice_url,
            pr.status, 
            pr.submitted_at,
            pr.processed_at,
            pr.rejection_reason,
            pr.payment_notification_pdf_url,
            pr.payment_receipt_url
         FROM payout_requests pr
         JOIN vendors v ON pr.vendor_id = v.id
         " . $where_clause . "
         ORDER BY (CASE 
                     WHEN pr.status = 'Submitted' THEN 1
                     WHEN pr.status = 'Pending' THEN 2
                     WHEN pr.status = 'Approved' THEN 3
                     WHEN pr.status = 'Rejected' THEN 4
                     ELSE 5
                   END), pr.submitted_at DESC
         LIMIT :limit OFFSET :offset"
    );

    foreach ($params as $key => &$val) {
        $stmt->bindParam($key, $val);
    }
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate WHT and add currency for each request
    foreach ($requests as &$request) {
        $wht_rate = 0;
        switch ($request['service_type']) {
            case 'Professional Service':
                $wht_rate = 0.05; // 5%
                break;
            case 'Goods/Products':
                $wht_rate = 0.03; // 3%
                break;
            case 'Rent':
                $wht_rate = 0.10; // 10%
                break;
        }
        $request['withholding_tax'] = $request['amount'] * $wht_rate;
        $request['currency'] = $default_currency;
    }
    unset($request); // Unset reference to the last element

    echo json_encode([
        'payouts' => $requests,
        'total' => $total_results,
        'page' => $page,
        'limit' => $limit
    ]);

} catch (PDOException $e) {
    error_log('Database error in get_payout_requests.php: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error.']);
}
?>