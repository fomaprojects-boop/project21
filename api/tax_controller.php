<?php
// api/tax_controller.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// Only Admins and Accountants can manage tax payments
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
            $total_stmt = $pdo->query("SELECT COUNT(*) FROM tax_payments");
            $total_records = $total_stmt->fetchColumn();
            $total_pages = ceil($total_records / $limit);

            // Query for paginated data
            $stmt = $pdo->prepare("SELECT id, payment_date, amount, financial_year, quarter, reference_number FROM tax_payments ORDER BY financial_year DESC, quarter ASC LIMIT :limit OFFSET :offset");
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $tax_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'data' => $tax_payments,
                'pagination' => [
                    'total_records' => $total_records,
                    'total_pages' => $total_pages,
                    'current_page' => $page,
                    'limit' => $limit
                ]
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to fetch tax payments: ' . $e->getMessage()]);
        }
        break;

    case 'POST':
        // Handle creating a new tax payment
        $data = json_decode(file_get_contents('php://input'), true);

        // --- Data Validation ---
        $payment_date = $data['payment_date'] ?? null;
        $amount = filter_var($data['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $financial_year = filter_var($data['financial_year'] ?? null, FILTER_VALIDATE_INT);
        $quarter = trim($data['quarter'] ?? '');
        $reference_number = trim($data['reference_number'] ?? null);

        if (empty($payment_date) || $amount === false || $amount <= 0 || empty($financial_year) || empty($quarter)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Payment date, a valid positive amount, financial year, and quarter are required.']);
            exit();
        }

        // Validate date format
        if (!DateTime::createFromFormat('Y-m-d', $payment_date)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid date format. Please use YYYY-MM-DD.']);
            exit();
        }
        // --- End Validation ---

        try {
            // Check if 4 quarters already exist for the financial year
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM tax_payments WHERE financial_year = ?");
            $count_stmt->execute([$financial_year]);
            $count = $count_stmt->fetchColumn();

            if ($count >= 4) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Cannot add more than 4 quarterly payments for the financial year ' . $financial_year . '.']);
                exit();
            }
            
            $sql = "INSERT INTO tax_payments (payment_date, amount, financial_year, quarter, reference_number) VALUES (?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$payment_date, $amount, $financial_year, $quarter, $reference_number]);

            $new_payment_id = $pdo->lastInsertId();

            echo json_encode(['status' => 'success', 'message' => 'Tax payment added successfully.', 'id' => $new_payment_id]);

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
