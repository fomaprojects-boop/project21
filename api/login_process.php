<?php
// Washa uonyeshaji wa makosa kwa ajili ya uchunguzi
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
header('Content-Type: application/json');
require 'db.php'; // Hakikisha hili faili lipo

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
    $stmt = $pdo->prepare("SELECT id, full_name, email, password, role FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 4. Hakiki taarifa za mtumiaji
    if ($user) {
        // Mtumiaji amepatikana, sasa hakiki nenosiri
        if (password_verify($password, $user['password'])) {
            // Nenosiri ni sahihi
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];
            
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
