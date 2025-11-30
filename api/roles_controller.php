<?php
// api/roles_controller.php
session_start();
header('Content-Type: application/json');

require_once 'db.php';
require_once 'helpers/PermissionHelper.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$tenant_id = getCurrentTenantId();

// Enforce Permission
if (!hasPermission($user_id, 'manage_roles')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access Denied: manage_roles permission required.']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    if ($method === 'GET') {
        if ($action === 'permissions') {
            // List all available system permissions
            $stmt = $pdo->query("SELECT * FROM permissions ORDER BY category, name");
            $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'data' => $permissions]);
        } else {
            // List Roles with their Permissions
            $stmt = $pdo->prepare("SELECT * FROM roles WHERE tenant_id = ?");
            $stmt->execute([$tenant_id]);
            $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch permissions for each role
            foreach ($roles as &$role) {
                $pStmt = $pdo->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?");
                $pStmt->execute([$role['id']]);
                $role['permissions'] = $pStmt->fetchAll(PDO::FETCH_COLUMN);
            }

            echo json_encode(['status' => 'success', 'data' => $roles]);
        }
    }
    elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        if ($action === 'create') {
            $name = trim($input['name']);
            $description = trim($input['description'] ?? '');
            $permissions = $input['permissions'] ?? []; // Array of permission IDs

            if (empty($name)) {
                echo json_encode(['status' => 'error', 'message' => 'Role name is required']);
                exit;
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO roles (tenant_id, name, description) VALUES (?, ?, ?)");
            $stmt->execute([$tenant_id, $name, $description]);
            $roleId = $pdo->lastInsertId();

            if (!empty($permissions)) {
                $insertPerm = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                foreach ($permissions as $permId) {
                    $insertPerm->execute([$roleId, $permId]);
                }
            }
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Role created successfully']);

        }
        elseif ($action === 'update') {
            $roleId = $input['id'];
            $name = trim($input['name']);
            $description = trim($input['description'] ?? '');
            $permissions = $input['permissions'] ?? [];

            // Verify ownership
            $check = $pdo->prepare("SELECT id FROM roles WHERE id = ? AND tenant_id = ?");
            $check->execute([$roleId, $tenant_id]);
            if (!$check->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'Role not found']);
                exit;
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE roles SET name = ?, description = ? WHERE id = ?");
            $stmt->execute([$name, $description, $roleId]);

            // Sync Permissions: Delete all and re-insert
            $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$roleId]);

            if (!empty($permissions)) {
                $insertPerm = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                foreach ($permissions as $permId) {
                    $insertPerm->execute([$roleId, $permId]);
                }
            }
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Role updated successfully']);
        }
        elseif ($action === 'delete') {
            $roleId = $input['id'];

            // Prevent deleting Role used by current user (Self-Lockout Prevention)
            if ($roleId == $_SESSION['role_id']) {
                echo json_encode(['status' => 'error', 'message' => 'Cannot delete your own assigned role.']);
                exit;
            }

            // Verify ownership
            $check = $pdo->prepare("SELECT id FROM roles WHERE id = ? AND tenant_id = ?");
            $check->execute([$roleId, $tenant_id]);
            if (!$check->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'Role not found']);
                exit;
            }

            // Check if users are assigned
            $userCheck = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id = ?");
            $userCheck->execute([$roleId]);
            if ($userCheck->fetchColumn() > 0) {
                echo json_encode(['status' => 'error', 'message' => 'Cannot delete role assigned to users. Reassign them first.']);
                exit;
            }

            $pdo->prepare("DELETE FROM roles WHERE id = ?")->execute([$roleId]);
            echo json_encode(['status' => 'success', 'message' => 'Role deleted successfully']);
        }
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
