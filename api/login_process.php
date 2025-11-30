<?php
// Washa uonyeshaji wa makosa kwa ajili ya uchunguzi
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
header('Content-Type: application/json');
require 'db.php';

// 1. Hakiki kama tumepokea data
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'Kosa: Hakuna data iliyopokelewa au format si sahihi.']);
    exit;
}

// 2. Hakiki kama email na password vimetumwa
if (empty($data['email']) || empty($data['password'])) {
    echo json_encode(['status' => 'error', 'message' => 'Please enter email and password.']);
    exit;
}

$email = $data['email'];
$password = $data['password'];

// 3. Tafuta mtumiaji kwenye database
try {
    // Select role_id and tenant_id
    $stmt = $pdo->prepare("SELECT id, full_name, email, password, role, role_id, tenant_id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 4. Hakiki taarifa za mtumiaji
    if ($user) {
        // Mtumiaji amepatikana, sasa hakiki nenosiri
        if (password_verify($password, $user['password'])) {

            // Check Tenant Status
            $tenantStmt = $pdo->prepare("SELECT subscription_status FROM tenants WHERE id = ?");
            $tenantStmt->execute([$user['tenant_id']]);
            $tenantStatus = $tenantStmt->fetchColumn();

            if ($tenantStatus === 'suspended') {
                echo json_encode(['status' => 'error', 'message' => 'Account suspended. Please contact support.']);
                exit;
            }

            // Nenosiri ni sahihi
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role']; // Legacy support
            $_SESSION['role_id'] = $user['role_id'];
            $_SESSION['tenant_id'] = $user['tenant_id'];
            
            // Fetch Permissions and Store in Session (Optional caching optimization)
            $permStmt = $pdo->prepare("SELECT p.slug FROM role_permissions rp JOIN permissions p ON rp.permission_id = p.id WHERE rp.role_id = ?");
            $permStmt->execute([$user['role_id']]);
            $_SESSION['permissions'] = $permStmt->fetchAll(PDO::FETCH_COLUMN);

            echo json_encode(['status' => 'success', 'message' => 'Login successful, redirecting...']);
        } else {
            // Nenosiri si sahihi
            echo json_encode(['status' => 'error', 'message' => 'Invalid email or password.']);
        }
    } else {
        // Mtumiaji hajapatikana
        echo json_encode(['status' => 'error', 'message' => 'Invalid email or password.']);
    }

} catch (PDOException $e) {
    // Hii itakamata makosa ya database (k.m., muunganiko umefeli)
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
