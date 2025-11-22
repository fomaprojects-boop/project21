<?php
// api/get_workflow_templates.php
header('Content-Type: application/json');
session_start();

// Ulinzi: Hakikisha mtumiaji ameingia
if (!isset($_SESSION['user_id'])) {
    echo json_encode([]); // Rudisha array tupu kama mtumiaji hajaingia
    exit();
}

require_once 'db.php'; // Hakikisha una faili la kuunganisha na database

try {
    $stmt = $pdo->prepare("SELECT id, title, description, category, icon_class, workflow_data FROM workflow_templates ORDER BY category, title");
    $stmt->execute();
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Decode the JSON data for each template before sending
    foreach ($templates as &$template) {
        $template['workflow_data'] = json_decode($template['workflow_data'], true);
    }

    echo json_encode($templates);

} catch (PDOException $e) {
    // Rudisha array tupu kama kuna kosa lolote
    // Unaweza pia kuweka mfumo wa kuripoti makosa (logging) hapa
    error_log('Database error in get_workflow_templates.php: ' . $e->getMessage());
    echo json_encode([]);
}

?>