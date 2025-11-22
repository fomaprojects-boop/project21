<?php
// api/upload_payroll.php
// Removed dependency on PhpSpreadsheet to fix 500 error due to missing vendor folder
// require_once __DIR__ . '/../vendor/autoload.php';

require_once 'db.php';
session_start();

// use PhpOffice\PhpSpreadsheet\IOFactory; // Removed

header('Content-Type: application/json');

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error_log');

// Shutdown function to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR || $error['type'] === E_COMPILE_ERROR)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Fatal Error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']]);
    }
});

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$month = $_POST['month'] ?? null;
$year = $_POST['year'] ?? null;
$approver_id = $_POST['approver_id'] ?? null;

if (empty($month) || empty($year) || !isset($_FILES['payroll_file'])) {
    echo json_encode(['status' => 'error', 'message' => 'Month, year, and payroll file are required.']);
    exit;
}

if (empty($approver_id)) {
     echo json_encode(['status' => 'error', 'message' => 'Please select an approver.']);
     exit;
}

// Convert month name to integer if necessary
$month_map = [
    'January' => 1, 'February' => 2, 'March' => 3, 'April' => 4,
    'May' => 5, 'June' => 6, 'July' => 7, 'August' => 8,
    'September' => 9, 'October' => 10, 'November' => 11, 'December' => 12
];

$month_val = $month;
if (isset($month_map[$month])) {
    $month_val = $month_map[$month];
} elseif (is_numeric($month)) {
    $month_val = intval($month);
}

if ($month_val < 1 || $month_val > 12) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid month selected.']);
    exit;
}

$file = $_FILES['payroll_file']['tmp_name'];
$original_filename = $_FILES['payroll_file']['name'];

// Check if file exists
if (!file_exists($file)) {
    echo json_encode(['status' => 'error', 'message' => 'Uploaded file not found.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Ingiza kwenye `payroll_batches`
    $stmt_batch = $pdo->prepare(
        "INSERT INTO payroll_batches (month, year, uploaded_by, approver_id, original_filename, status)
         VALUES (?, ?, ?, ?, ?, 'pending_approval')"
    );
    $stmt_batch->execute([$month_val, $year, $_SESSION['user_id'], $approver_id, $original_filename]);
    $batch_id = $pdo->lastInsertId();

    // 2. Ingiza kila 'entry' kwenye `payroll_entries`
    $stmt_entry = $pdo->prepare(
        "INSERT INTO payroll_entries (batch_id, employee_name, employee_email, basic_salary, allowances, deductions, income_tax, net_salary)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );

    // Use native CSV parsing
    if (($handle = fopen($file, "r")) !== FALSE) {
        $header_row = fgetcsv($handle, 1000, ","); // Skip header row

        // Verify header row (optional but good practice)
        if (!$header_row) {
             throw new Exception("The CSV file is empty or could not be read.");
        }

        $total_amount = 0;
        $row_count = 0;

        while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
            // Expecting columns: Name, Email, Basic, Allowances, Deductions, Tax, Net
            // Indices: 0, 1, 2, 3, 4, 5, 6
            if (count($row) < 7) {
                continue; // Skip malformed rows
            }

            $employee_name = $row[0];
            $employee_email = $row[1];

            // Hakikisha 'row' ina data kabla ya kuingiza
            if (empty($employee_name) && empty($employee_email)) {
                continue; // Ruka 'rows' tupu
            }

            $basic_salary = floatval(str_replace(',', '', $row[2]));
            $allowances = floatval(str_replace(',', '', $row[3]));
            $deductions = floatval(str_replace(',', '', $row[4]));
            $income_tax = floatval(str_replace(',', '', $row[5]));
            $net_salary = floatval(str_replace(',', '', $row[6]));

            $stmt_entry->execute([
                $batch_id, $employee_name, $employee_email,
                $basic_salary, $allowances, $deductions, $income_tax, $net_salary
            ]);

            $total_amount += $net_salary;
            $row_count++;
        }
        fclose($handle);

        if ($row_count === 0) {
             throw new Exception("No valid data rows found in the CSV file.");
        }

        // 3. Sasisha `total_amount` kwenye 'batch'
        $stmt_update_batch = $pdo->prepare("UPDATE payroll_batches SET total_amount = ? WHERE id = ?");
        $stmt_update_batch->execute([$total_amount, $batch_id]);

        $pdo->commit();

        echo json_encode(['status' => 'success', 'message' => 'Payroll data uploaded successfully and is pending approval.']);
        exit;
    } else {
        throw new Exception("Could not open the file.");
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Upload Payroll Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
