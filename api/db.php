<?php
$host = 'localhost';
$dbname = 'app_chatmedb';
$user = 'app_chatmedb';
$password = 'chatme2025@';

try {
    // Anzisha muunganisho kwa kutumia PDO (njia ya kisasa na salama)
    // Updated to utf8mb4 to support Emojis in WhatsApp messages
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $password);

    // Weka PDO itoe errors kama zikitokea
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Weka timezone iwe East Africa Time (+3:00)
    $pdo->exec("SET time_zone = '+03:00'");

    // --- Schema Auto-Fix: Ensure whatsapp_status column exists ---
    $schema_lock = __DIR__ . '/schema_whatsapp_status.lock';
    if (!file_exists($schema_lock)) {
        try {
            // Add column if not exists
            $pdo->exec("ALTER TABLE users ADD COLUMN whatsapp_status ENUM('Pending', 'Connected', 'Disconnected') DEFAULT 'Pending'");
            // Mark as done on success
            file_put_contents($schema_lock, date('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            // Check for duplicate column error (1060) or similar
            // If it exists, we can also mark as done.
            if ($e->errorInfo[1] == 1060) {
                file_put_contents($schema_lock, date('Y-m-d H:i:s'));
            } else {
                // Log other errors but continue (don't crash app, though feature might fail)
                // Do NOT create lock file so it retries next time
                file_put_contents(__DIR__ . '/../db_migration_error.log', date('Y-m-d H:i:s') . " - Migration Failed: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
    }
    // -----------------------------------------------------------

} catch (PDOException $e) {
    // Ikishindikana, toa ujumbe wa kosa katika format ya JSON
    header('Content-Type: application/json');
    http_response_code(500); // Internal Server Error
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    // Sitisha utekelezaji
    die();
}
?>
