<?php
session_start();
header('Content-Type: application/json');

require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$action = $_GET['action'] ?? null;

switch ($action) {
    case 'get_financial_summary':
        get_financial_summary();
        break;
    case 'close_year':
        close_financial_year();
        break;
    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action specified']);
        break;
}

require_once 'financial_helpers.php';

function get_financial_summary() {
    global $pdo;
    $year = $_GET['year'] ?? date('Y');

    try {
        $summary_data = get_financial_summary_data($year);
        echo json_encode(['status' => 'success', 'data' => $summary_data]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function close_financial_year() {
    global $pdo;
    $year = $_POST['year'] ?? null;

    if (empty($year)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Year is required.']);
        return;
    }

    try {
        $pdo->beginTransaction();
        
        // Check if the year is already closed
        $stmt = $pdo->prepare("SELECT is_closed FROM financial_years WHERE year = ?");
        $stmt->execute([$year]);
        $is_closed = $stmt->fetchColumn();

        if ($is_closed) {
            throw new Exception("Financial year {$year} is already closed.");
        }
        
        // Mark the year as closed
        $stmt = $pdo->prepare("INSERT INTO financial_years (year, is_closed, closing_date) VALUES (?, TRUE, CURDATE()) ON DUPLICATE KEY UPDATE is_closed = TRUE, closing_date = CURDATE()");
        $stmt->execute([$year]);

        // Optional: Record depreciation as an expense
        $total_depreciation = calculate_total_depreciation($year);
        if ($total_depreciation > 0) {
            $stmt = $pdo->prepare("INSERT INTO direct_expenses (user_id, amount, expense_type, date, reference, status, type) VALUES (?, ?, ?, ?, ?, 'Approved', 'system')");
            // Assuming user_id 1 is the system/admin
            $stmt->execute([1, $total_depreciation, 'depreciation', "{$year}-12-31", "Annual Depreciation for {$year}", 'Approved']);
        }
        
        // Add to audit trail
        $stmt = $pdo->prepare("INSERT INTO audit_trails (user_id, action, target_id, target_type) VALUES (?, 'close_year', ?, 'financial_year')");
        $stmt->execute([$_SESSION['user_id'], $year]);

        $pdo->commit();

        echo json_encode(['status' => 'success', 'message' => "Financial year {$year} has been closed successfully."]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
?>
