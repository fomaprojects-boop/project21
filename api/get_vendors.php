<?php
// api/get_vendors.php
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

try {
    // Get total count
    $total_stmt = $pdo->query("SELECT COUNT(*) FROM vendors");
    $total_results = $total_stmt->fetchColumn();

    // Kwa sasa tunasoma wauzaji wote. Baadaye tunaweza kuongeza "user_id" ili kila mtumiaji aone wake tu.
    $stmt = $pdo->prepare("SELECT id, full_name, email, phone FROM vendors ORDER BY full_name LIMIT :limit OFFSET :offset");
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'vendors' => $vendors,
        'total' => $total_results,
        'page' => $page,
        'limit' => $limit
    ]);

} catch (PDOException $e) {
    error_log('Database error in get_vendors.php: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error.']);
}
?>