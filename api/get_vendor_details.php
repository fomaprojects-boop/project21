<?php
// api/get_vendor_details.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

require_once 'db.php';

$vendor_id = $_GET['id'] ?? null;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 5;
$offset = ($page - 1) * $limit;
$status = isset($_GET['status']) ? $_GET['status'] : 'All';


if (empty($vendor_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Vendor ID is missing.']);
    exit();
}

try {
    $response = [];

    // Hatua 1: Pata taarifa za Vendor
    $stmt = $pdo->prepare("SELECT id, full_name, email, phone FROM vendors WHERE id = ?");
    $stmt->execute([$vendor_id]);
    $vendor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vendor) {
        throw new Exception("Vendor not found.");
    }
    $response['vendor'] = $vendor;

    // Get total count for pagination
    $count_sql = "SELECT COUNT(*) FROM payout_requests WHERE vendor_id = :vendor_id";
    $count_params = ['vendor_id' => $vendor_id];
    if ($status !== 'All') {
        $count_sql .= " AND status = :status";
        $count_params['status'] = $status;
    }
    $total_stmt = $pdo->prepare($count_sql);
    $total_stmt->execute($count_params);
    $total_results = $total_stmt->fetchColumn();

    // Hatua 2: Pata historia yote ya malipo (payouts) ya huyo vendor
    $stmt = $pdo->prepare(
        "SELECT 
            id, 
            amount, 
            transaction_reference,
            service_type, 
            status, 
            submitted_at, 
            processed_at,
            invoice_url,
            payment_notification_pdf_url,
            payment_receipt_url,
            rejection_reason,
            payment_method,
            bank_name,
            account_name,
            account_number,
            mobile_network,
            mobile_phone

          FROM payout_requests 
          WHERE vendor_id = :vendor_id " . ($status !== 'All' ? " AND status = :status" : "") . "
          ORDER BY (CASE 
                      WHEN status = 'Submitted' THEN 1
                      WHEN status = 'Pending' THEN 2
                      WHEN status = 'Approved' THEN 3
                      WHEN status = 'Rejected' THEN 4
                      ELSE 5
                    END), COALESCE(processed_at, submitted_at) DESC
          LIMIT :limit OFFSET :offset"
    );
    
    // Bind values kwa kutumia majina (named parameters)
    $stmt->bindValue(':vendor_id', $vendor_id, PDO::PARAM_INT);
    if ($status !== 'All') {
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $payouts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate Withholding Tax for each payout
    foreach ($payouts as &$payout) {
        if ($payout['status'] === 'Approved') {
            $wht_rate = 0;
            switch ($payout['service_type']) {
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
            $payout['withholding_tax_amount'] = $payout['amount'] * $wht_rate;
        } else {
            $payout['withholding_tax_amount'] = 0;
        }
    }
    unset($payout); // Unset reference

    $response['payouts'] = $payouts;
    $response['total'] = $total_results;
    $response['page'] = $page;
    $response['limit'] = $limit;
    
    // Ongeza 'status' => 'success' ili kurahisisha utambuzi upande wa JavaScript
    $response['status'] = 'success'; 

    echo json_encode($response);

} catch (PDOException $e) {
    error_log('Database error in get_vendor_details.php: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error.']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>