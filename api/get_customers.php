<?php
// api/get_customers.php
require_once __DIR__ . '/auth_check.php'; // ONGEZA HII KWA USALAMA
require_once __DIR__ . '/db.php';

// Hakikisha Content-Type imewekwa (auth_check inafanya hivi, lakini ni vizuri kuwa nayo hapa pia)
header('Content-Type: application/json');

try {
    // Badilisha query ivute data kutoka kwenye jedwali la 'customers'
    $stmt = $pdo->query("SELECT id, name, email, phone FROM customers ORDER BY name ASC");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($customers);

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database Error in get_customers.php: " . $e->getMessage());
    echo json_encode([]); // Rudisha array tupu ikiwa kuna kosa
}
?>