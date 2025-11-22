<?php
// api/get_unassigned_contacts.php
require_once __DIR__ . '/auth_check.php'; // ONGEZA HII KWA USALAMA
require_once __DIR__ . '/db.php';

// Hakikisha Content-Type imewekwa (auth_check inafanya hivi)
header('Content-Type: application/json');

try {
    // Chagua contacts ambao hawana customer_id
    $stmt = $pdo->prepare("SELECT id, name FROM contacts WHERE customer_id IS NULL ORDER BY name ASC");
    $stmt->execute();
    $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($contacts); // Rudisha array (inaweza kuwa tupu kama hakuna)

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database Error in get_unassigned_contacts.php: " . $e->getMessage()); // Ni bora kulog error kwa ajili yako
    echo json_encode([]); // Rudisha array tupu ikiwa kuna kosa
}
?>