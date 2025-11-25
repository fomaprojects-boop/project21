<?php
header('Content-Type: application/json');
require_once 'db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in.']);
    exit;
}

$conversationId = $_GET['conversation_id'] ?? null;

if (!$conversationId) {
    echo json_encode(['success' => false, 'message' => 'Conversation ID is required.']);
    exit;
}

try {
    // First, get the contact_id from the conversations table
    $stmt = $pdo->prepare("SELECT contact_id FROM conversations WHERE id = ?");
    $stmt->execute([$conversationId]);
    $contactId = $stmt->fetchColumn();

    if (!$contactId) {
        echo json_encode(['success' => false, 'message' => 'Contact not found for this conversation.']);
        exit;
    }

    // Now, fetch all details for that contact
    $stmt = $pdo->prepare("SELECT name, phone_number, email, notes FROM contacts WHERE id = ?");
    $stmt->execute([$contactId]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($contact) {
        echo json_encode(['success' => true, 'contact' => $contact]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Could not retrieve contact details.']);
    }

} catch (PDOException $e) {
    // Log error to a file for debugging
    error_log("Database error in get_contact_details.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A database error occurred.']);
}
