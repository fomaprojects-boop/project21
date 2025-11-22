<?php
// api/get_users.php
session_start();
header('Content-Type: application/json');

// Kwanza, hakikisha mtumiaji ameingia
if (!isset($_SESSION['user_id'])) {
    http_response_code(401); // Unauthorized
    echo json_encode([]); // Rudisha array tupu kama hayupo
    exit();
}

// Unganisha na database
require_once 'db.php'; 

try {
    $sql = "SELECT id, full_name, email, role, status, avatar_char FROM users";
    $params = [];

    if (isset($_GET['role'])) {
        $roles = explode(',', $_GET['role']);
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $sql .= " WHERE role IN ($placeholders)";
        $params = $roles;
    }

    $sql .= " ORDER BY full_name ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($users);

} catch (PDOException $e) {
    // Ikitokea kosa la database, rudisha ujumbe wa kosa
    http_response_code(500); // Internal Server Error
    // Hii ni kwa ajili ya uchunguzi tu, kuona kama kuna kosa la DB
    echo json_encode([
        'status' => 'error', 
        'message' => 'Database query failed: ' . $e->getMessage()
    ]);
}
?>