<?php
// api/add_vendor.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);

$name = trim($data['name'] ?? '');
$email = trim($data['email'] ?? '');
$phone = trim($data['phone'] ?? '');

if (empty($name) || empty($email) || empty($phone)) {
    echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
    exit();
}

try {
    // Angalia kama vendor tayari yupo (kwa email au simu)
    $stmt = $pdo->prepare("SELECT id FROM vendors WHERE email = ? OR phone = ?");
    $stmt->execute([$email, $phone]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'A vendor with this email or phone number already exists.']);
        exit();
    }

    // Ingiza vendor mpya
    $stmt = $pdo->prepare("INSERT INTO vendors (full_name, email, phone) VALUES (?, ?, ?)");
    $stmt->execute([$name, $email, $phone]);

    echo json_encode(['status' => 'success', 'message' => 'Vendor added successfully.']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
