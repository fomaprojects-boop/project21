<?php
$host = 'localhost';
$dbname = 'app_chatmedb';
$user = 'app_chatmedb';
$password = 'chatme2025@';

try {
    // Anzisha muunganisho kwa kutumia PDO (njia ya kisasa na salama)
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $password);

    // Weka PDO itoe errors kama zikitokea
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Weka timezone iwe East Africa Time (+3:00)
    $pdo->exec("SET time_zone = '+03:00'");

} catch (PDOException $e) {
    // Ikishindikana, toa ujumbe wa kosa katika format ya JSON
    header('Content-Type: application/json');
    http_response_code(500); // Internal Server Error
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    // Sitisha utekelezaji
    die();
}
