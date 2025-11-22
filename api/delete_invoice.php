<?php
// api/delete_invoice.php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$invoice_id = $data['id'] ?? null;

if (!$invoice_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Document ID is required.']);
    exit();
}

try {
    $pdo->beginTransaction();

    // Check if the financial year is closed
    $stmt = $pdo->prepare("SELECT i.issue_date FROM invoices i WHERE i.id = ?");
    $stmt->execute([$invoice_id]);
    $issue_date = $stmt->fetchColumn();

    if ($issue_date) {
        $year = date('Y', strtotime($issue_date));
        $stmt = $pdo->prepare("SELECT is_closed FROM financial_years WHERE year = ?");
        $stmt->execute([$year]);
        $is_closed = $stmt->fetchColumn();

        if ($is_closed) {
            throw new Exception('Cannot delete invoice in a closed financial year.');
        }
    }

    // Check if the user owns the document before deleting
    $stmt = $pdo->prepare("SELECT pdf_url FROM invoices WHERE id = ? AND user_id = ?");
    $stmt->execute([$invoice_id, $user_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        throw new Exception("Document not found or you do not have permission to delete it.");
    }

    // Delete associated items first
    $stmt_items = $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?");
    $stmt_items->execute([$invoice_id]);

    // Delete the main document
    $stmt_inv = $pdo->prepare("DELETE FROM invoices WHERE id = ?");
    $stmt_inv->execute([$invoice_id]);

    // Optionally, delete the physical PDF file
    if (!empty($invoice['pdf_url'])) {
        $pdf_path = __DIR__ . '/../' . $invoice['pdf_url'];
        if (file_exists($pdf_path)) {
            unlink($pdf_path);
        }
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => 'Document deleted successfully.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
