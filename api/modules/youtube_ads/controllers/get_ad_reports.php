<?php
// api/modules/youtube_ads/controllers/get_ad_reports.php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../db.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'User not authenticated.']);
    exit;
}

$tenant_id = $_SESSION['user_id'];
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 3;
$offset = ($page - 1) * $limit;

try {
    // Fixed: Query from ad_reports instead of ad_daily_stats
    $total_stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT r.id)
        FROM ad_reports r
        JOIN ads a ON r.ad_id = a.id
        WHERE a.tenant_id = ?
    ");
    $total_stmt->execute([$tenant_id]);
    $total = $total_stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.report_date,
            r.pdf_path,
            a.title as ad_title,
            adv.name as advertiser_name,
            r.created_at as generated_at
        FROM ad_reports r
        JOIN ads a ON r.ad_id = a.id
        JOIN advertisers adv ON a.advertiser_id = adv.id
        WHERE a.tenant_id = ?
        ORDER BY r.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bindValue(1, $tenant_id, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();

    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'reports' => $reports,
        'total' => (int)$total,
        'page' => $page,
        'limit' => $limit
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}