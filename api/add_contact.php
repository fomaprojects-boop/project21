<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['name']) || empty($data['phone'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Name and phone number are required.']);
    exit();
}

$name = trim($data['name']);
$email = trim($data['email'] ?? ''); // Pata email, iweke tupu kama haipo
$phone = trim($data['phone']);


// --- Validation ya Email (Optional) ---
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
    exit();
}


try {
    // Tunaongeza anwani mpya kwenye jedwali la 'contacts'
    $stmt = $pdo->prepare("INSERT INTO contacts (name, email, phone_number) VALUES (?, ?, ?)");
    if ($stmt->execute([$name, $email ?: null, $phone])) {
        echo json_encode(['status' => 'success', 'message' => 'Contact added successfully.']);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to add contact.']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    if ($e->errorInfo[1] == 1062) { // Kosa la namba kurudiwa
         echo json_encode(['status' => 'error', 'message' => 'This phone number already exists.']);
    } else {
         echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
}
?>