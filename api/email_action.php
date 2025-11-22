<?php
require_once 'db.php';
require_once 'config.php';

// This is a public-facing endpoint, so no session is required.

header('Content-Type: text/html');

$token = $_GET['token'] ?? null;
$action = $_GET['action'] ?? null;

if (!$token || !$action) {
    http_response_code(400);
    die("<h1>Error</h1><p>Missing required parameters.</p>");
}

try {
    $pdo->beginTransaction();

    // Determine which token to look for
    $tokenColumn = ($action === 'approve') ? 'approve_token' : 'edits_token';

    // Find the proof associated with the token
    $stmt = $pdo->prepare(
        "SELECT id, job_order_id, status, token_expiry 
         FROM digital_proofs 
         WHERE {$tokenColumn} = :token"
    );
    $stmt->execute([':token' => $token]);
    $proof = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$proof) {
        http_response_code(404);
        die("<h1>Invalid Link</h1><p>This action link is not valid. It may have already been used.</p>");
    }

    // Check if the token has expired
    $expiry = new DateTime($proof['token_expiry']);
    $now = new DateTime();
    if ($now > $expiry) {
        http_response_code(400);
        die("<h1>Link Expired</h1><p>This action link has expired. Please contact support.</p>");
    }

    $newStatus = '';
    $message = '';

    if ($action === 'approve') {
        $newStatus = 'Approved';
        $message = "<h1>Proof Approved</h1><p>Thank you! The job has been approved for printing.</p>";
        
        // Update job order status as well
        $pdo->prepare("UPDATE job_orders SET status = 'Printing' WHERE id = :id")->execute(['id' => $proof['job_order_id']]);

    } elseif ($action === 'request_edits' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // This is the final submission from the request_edits.php form
        $notes = htmlspecialchars($_POST['notes'] ?? 'No notes provided.');
        $newStatus = 'Needs Revision';

        // Fetch the user ID associated with the job to log who made the revision request
        $userStmt = $pdo->prepare("SELECT customer_id FROM job_orders WHERE id = :id");
        $userStmt->execute(['id' => $proof['job_order_id']]);
        $customerId = $userStmt->fetchColumn();

        $revStmt = $pdo->prepare("INSERT INTO proof_revisions (proof_id, user_id, notes) VALUES (:proof_id, :user_id, :notes)");
        $revStmt->execute(['proof_id' => $proof['id'], ':user_id' => $customerId, 'notes' => $notes]);
        
        $pdo->prepare("UPDATE job_orders SET status = 'Designing' WHERE id = :id")->execute(['id' => $proof['job_order_id']]);

        $message = "<h1>Revisions Submitted</h1><p>Thank you! Your feedback has been sent to the design team.</p>";
    } else {
        // If the action is 'request_edits' but it's not a POST, it means the user just clicked the link.
        // The request_edits.php page will handle the form display.
        // Any other action or invalid method combination is an error.
        http_response_code(400);
        die("<h1>Invalid Action</h1><p>The requested action is not valid.</p>");
    }

    // Update the proof status
    $updateStmt = $pdo->prepare("UPDATE digital_proofs SET status = :status, approve_token = NULL, edits_token = NULL, token_expiry = NULL WHERE id = :id");
    $updateStmt->execute([':status' => $newStatus, ':id' => $proof['id']]);

    // --- Internal Notification ---
    $jobInfoStmt = $pdo->prepare("SELECT jo.tracking_number, u.full_name FROM job_orders jo JOIN users u ON jo.customer_id = u.id WHERE jo.id = :id");
    $jobInfoStmt->execute(['id' => $proof['job_order_id']]);
    $jobInfo = $jobInfoStmt->fetch(PDO::FETCH_ASSOC);

    $notificationSubject = "Job #{$jobInfo['tracking_number']} Status Update: {$newStatus}";
    $notificationBody = "
        <p>This is an automated notification for job #<strong>{$jobInfo['tracking_number']}</strong>.</p>
        <p>The client, {$jobInfo['full_name']}, has updated the proof status to: <strong>{$newStatus}</strong>.</p>
    ";
    if ($newStatus === 'Needs Revision') {
        $notificationBody .= "<p><strong>Client Feedback:</strong><br/>" . nl2br($notes) . "</p>";
    }
    $notificationBody .= "<p>Please log in to the system to view the details.</p>";

    // In a real system, you would look up the specific designer or admin to notify.
    // For now, we'll send to the general admin email.
    sendEmail(SMTP_FROM_EMAIL, $notificationSubject, $notificationBody);
    // --- End Internal Notification ---


    $pdo->commit();

    echo $message;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log("Email action failed: " . $e->getMessage());
    die("<h1>An Error Occurred</h1><p>We could not process your request at this time. Please try again later.</p>");
}
