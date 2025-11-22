<?php
session_start();
header('Content-Type: application/json');

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please log in again.']);
    exit();
}

require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);

// --- Data Validation ---
$campaign_name = trim($data['campaign_name'] ?? '');
$message_type = $data['message_type'] ?? 'custom';
$message_body = trim($data['message_body'] ?? '');
$template_id = $data['template_id'] ?? null;
$schedule_type = $data['schedule_type'] ?? 'now';
$scheduled_at_string = $data['scheduled_at'] ?? '';

if (empty($campaign_name)) {
    echo json_encode(['status' => 'error', 'message' => 'Campaign Name is required.']);
    exit();
}

if ($message_type === 'custom' && empty($message_body)) {
    echo json_encode(['status' => 'error', 'message' => 'Message body is required for a custom message.']);
    exit();
}

if ($message_type === 'template' && empty($template_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Please select a template.']);
    exit();
}

// --- Schedule Handling ---
if ($schedule_type === 'later' && !empty($scheduled_at_string)) {
    try {
        // Convert local datetime-local input to a format MySQL understands
        $scheduled_at = new DateTime($scheduled_at_string);
        $scheduled_at_mysql = $scheduled_at->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid schedule date format.']);
        exit();
    }
} else {
    // If 'Send Now', schedule it for the current time
    $now = new DateTime('now', new DateTimeZone('UTC')); // Using UTC for consistency
    $scheduled_at_mysql = $now->format('Y-m-d H:i:s');
}

// --- Database Insertion ---
try {
    $stmt = $pdo->prepare(
        "INSERT INTO broadcasts (campaign_name, message_body, template_id, status, scheduled_at) 
         VALUES (?, ?, ?, ?, ?)"
    );
    
    $status = ($schedule_type === 'now') ? 'Sent' : 'Scheduled'; // Assuming 'now' means it gets sent immediately
    
    $stmt->execute([
        $campaign_name,
        ($message_type === 'custom') ? $message_body : null,
        ($message_type === 'template') ? $template_id : null,
        $status,
        $scheduled_at_mysql
    ]);
    
    // In a real system, you would now add the broadcast job to a queue to be processed.
    // For this demo, we just save it.

    echo json_encode(['status' => 'success', 'message' => "Broadcast '{$campaign_name}' has been successfully created and scheduled."]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}

?>
