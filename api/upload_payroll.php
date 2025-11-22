<?php
// api/upload_payroll.php
require_once __DIR__ . '/../vendor/autoload.php';
require_once 'db.php';
session_start();

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json');

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

if (empty($month) || empty($year) || !isset($_FILES['payroll_file'])) {
    echo json_encode(['status' => 'error', 'message' => 'Month, year, and payroll file are required.']);
    exit;
}

$file = $_FILES['payroll_file']['tmp_name'];
$original_filename = $_FILES['payroll_file']['name'];

try {
    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet();
    $data = $sheet->toArray(null, true, true, true);

    if (count($data) < 2) {
        throw new Exception("The Excel file is empty or has no data rows.");
    }

    // Ondoa 'header row'
    $header_row = array_shift($data);
    
    $pdo->beginTransaction();

    // 1. Ingiza kwenye `payroll_batches`
    $stmt_batch = $pdo->prepare(
        "INSERT INTO payroll_batches (month, year, uploaded_by, approver_id, original_filename, status) 
         VALUES (?, ?, ?, ?, ?, 'pending_approval')"
    );
    $stmt_batch->execute([$month, $year, $_SESSION['user_id'], $_POST['approver_id'], $original_filename]);
    $batch_id = $pdo->lastInsertId();

    // 2. Ingiza kila 'entry' kwenye `payroll_entries`
    $stmt_entry = $pdo->prepare(
        "INSERT INTO payroll_entries (batch_id, employee_name, employee_email, basic_salary, allowances, deductions, income_tax, net_salary) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $total_amount = 0;
    foreach ($data as $row) {
        // 'row' inatoa kama associative array, A=Jina, B=Email, nk.
        $employee_name = $row['A'];
        $employee_email = $row['B'];
        $basic_salary = floatval($row['C']);
        $allowances = floatval($row['D']);
        $deductions = floatval($row['E']);
        $income_tax = floatval($row['F']);
        $net_salary = floatval($row['G']);

        // Hakikisha 'row' ina data kabla ya kuingiza
        if (empty($employee_name) && empty($employee_email)) {
            continue; // Ruka 'rows' tupu
        }
        
        $stmt_entry->execute([
            $batch_id, $employee_name, $employee_email, 
            $basic_salary, $allowances, $deductions, $income_tax, $net_salary
        ]);
        
        $total_amount += $net_salary;
    }

    // 3. Sasisha `total_amount` kwenye 'batch'
    $stmt_update_batch = $pdo->prepare("UPDATE payroll_batches SET total_amount = ? WHERE id = ?");
    $stmt_update_batch->execute([$total_amount, $batch_id]);

    $pdo->commit();

    echo json_encode(['status' => 'success', 'message' => 'Payroll data uploaded successfully and is pending approval.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
