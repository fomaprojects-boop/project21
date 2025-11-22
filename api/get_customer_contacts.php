<?php
// api/get_customer_contacts.php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

// Hakikisha user ameingia na customer_id ipo
if (!isset($_SESSION['user_id'])) {
    http_response_code(401); // Unauthorized
    echo json_encode([]); // Rudisha array tupu
    exit();
}

if (!isset($_GET['customer_id']) || !is_numeric($_GET['customer_id'])) {
    http_response_code(400); // Bad Request
    echo json_encode([]); // Rudisha array tupu
    exit();
}

$customerId = (int)$_GET['customer_id'];

try {
    // Pata contacts wote walio chini ya customer huyu
    // Tunachukua 'id', 'name', na 'email' kwa ajili ya fomu
    $stmt = $pdo->prepare("SELECT id, name, email FROM contacts WHERE customer_id = ? ORDER BY name ASC");
    $stmt->execute([$customerId]);
    $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Rudisha contacts kama JSON array (hata kama ni tupu)
    echo json_encode($contacts);

} catch (PDOException $e) {
    // Tuma error kama kuna tatizo la database
    http_response_code(500); // Internal Server Error
    error_log("Database Error in get_customer_contacts: " . $e->getMessage()); // Log the actual error
    echo json_encode([]); // Rudisha array tupu ili kuzuia kuvunjika kwa frontend
}
?>