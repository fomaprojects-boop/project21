<?php
// Mock session
session_start();
$_SESSION['user_id'] = 1;

// Define a mock PDO
class MockPDO extends PDO {
    public function __construct() {}
    public function prepare($stmt, $options = null) { return new MockStmt(); }
    public function beginTransaction() { return true; }
    public function commit() { return true; }
    public function lastInsertId($name = null) { return 1; }
}

class MockStmt extends PDOStatement {
    public function execute($params = null) {
        // Log query params for verification
        file_put_contents('tests/query_log.txt', print_r($params, true), FILE_APPEND);
        return true;
    }
    public function fetchAll($mode = PDO::FETCH_DEFAULT, ...$args) {
        // Return mock data
        return [
            ['id' => 1, 'name' => 'Approved Temp', 'status' => 'APPROVED'],
            ['id' => 2, 'name' => 'Pending Temp', 'status' => 'PENDING']
        ];
    }
}

// Override DB connection
$pdo = new MockPDO();

// Simulate GET
$_GET['status'] = 'APPROVED';

// Include the API file (modified to use mock PDO if set, or we just test the logic)
// Since we can't easily mock the PDO inside the included file without modifying it,
// we will instead perform a real DB test using the SQLite or MySQL available in the env if possible.
// But the env might not have a running DB for PHP CLI.
// Let's rely on creating a PHP script that interacts with the ACTUAL db.php but we manually insert data.
?>
