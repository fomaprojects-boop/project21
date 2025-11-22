<?php
// api/investments_controller.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// Only Admins and Accountants can manage investments
require_role(['Admin', 'Accountant']);

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        try {
            // Pagination parameters
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 4; // Default limit 4 as requested
            $offset = ($page - 1) * $limit;

            // Query for total count
            $total_stmt = $pdo->query("SELECT COUNT(*) FROM investments");
            $total_records = $total_stmt->fetchColumn();
            $total_pages = ceil($total_records / $limit);

            // Query for paginated data
            $stmt = $pdo->prepare("SELECT id, description, investment_type, quantity, purchase_date, purchase_cost FROM investments ORDER BY purchase_date DESC LIMIT :limit OFFSET :offset");
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $investments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'data' => $investments,
                'pagination' => [
                    'total_records' => $total_records,
                    'total_pages' => $total_pages,
                    'current_page' => $page,
                    'limit' => $limit
                ]
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to fetch investments: ' . $e->getMessage()]);
        }
        break;

    case 'POST':
        // Handle creating a new investment
        $data = json_decode(file_get_contents('php://input'), true);

        // --- Data Validation ---
        $description = trim($data['description'] ?? '');
        $investment_type = trim($data['investment_type'] ?? '');
        $quantity = !empty($data['quantity']) ? filter_var($data['quantity'], FILTER_VALIDATE_FLOAT) : null;
        $purchase_date = $data['purchase_date'] ?? null;
        $purchase_cost = filter_var($data['purchase_cost'] ?? null, FILTER_VALIDATE_FLOAT);

        if (empty($description) || empty($investment_type) || empty($purchase_date) || $purchase_cost === false || $purchase_cost <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Description, type, purchase date, and a valid positive purchase cost are required.']);
            exit();
        }

        // Validate date format
        if (!DateTime::createFromFormat('Y-m-d', $purchase_date)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid date format. Please use YYYY-MM-DD.']);
            exit();
        }
        // --- End Validation ---

        try {
            $sql = "INSERT INTO investments (description, investment_type, quantity, purchase_date, purchase_cost) VALUES (?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$description, $investment_type, $quantity, $purchase_date, $purchase_cost]);

            $new_investment_id = $pdo->lastInsertId();

            echo json_encode(['status' => 'success', 'message' => 'Investment added successfully.', 'id' => $new_investment_id]);

        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        break;

    default:
        http_response_code(405); // Method Not Allowed
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
        break;
}
?>
