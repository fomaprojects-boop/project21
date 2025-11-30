<?php
$host = 'localhost';
$dbname = 'app_chatmedb';
$user = 'app_chatmedb';
$password = 'chatme2025@';

try {
    // Anzisha muunganisho kwa kutumia PDO (njia ya kisasa na salama)
    // Updated to utf8mb4 to support Emojis in WhatsApp messages
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $password);

    // Weka PDO itoe errors kama zikitokea
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Weka timezone iwe East Africa Time (+3:00)
    $pdo->exec("SET time_zone = '+03:00'");

    // --- Schema Auto-Fix: Ensure whatsapp_status column exists ---
    $schema_lock = __DIR__ . '/schema_whatsapp_status.lock';
    if (!file_exists($schema_lock)) {
        try {
            // Add column if not exists
            $pdo->exec("ALTER TABLE users ADD COLUMN whatsapp_status ENUM('Pending', 'Connected', 'Disconnected') DEFAULT 'Pending'");
            // Mark as done on success
            file_put_contents($schema_lock, date('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            // Check for duplicate column error (1060) or similar
            // If it exists, we can also mark as done.
            if ($e->errorInfo[1] == 1060) {
                file_put_contents($schema_lock, date('Y-m-d H:i:s'));
            } else {
                // Log other errors but continue (don't crash app, though feature might fail)
                // Do NOT create lock file so it retries next time
                file_put_contents(__DIR__ . '/../db_migration_error.log', date('Y-m-d H:i:s') . " - Migration Failed: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
    }

    // --- Schema Auto-Fix: Add exchange_rate column to users ---
    // Allows currency conversion logic
    $schema_lock_exchange_rate = __DIR__ . '/schema_exchange_rate.lock';
    if (!file_exists($schema_lock_exchange_rate)) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN exchange_rate DECIMAL(10, 2) DEFAULT 1.00");
            file_put_contents($schema_lock_exchange_rate, date('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1060) {
                file_put_contents($schema_lock_exchange_rate, date('Y-m-d H:i:s'));
            } else {
                file_put_contents(__DIR__ . '/../db_migration_error.log', date('Y-m-d H:i:s') . " - Exchange Rate Column Migration Failed: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
    }

    // --- Schema Auto-Fix: Ensure provider_message_id exists for Status Ticks ---
    $schema_lock_ticks = __DIR__ . '/schema_provider_msg_id.lock';
    if (!file_exists($schema_lock_ticks)) {
        try {
            // Add column if not exists
            // Using VARCHAR(255) for Meta WAMID and INDEX for fast lookups during webhook updates
            $pdo->exec("ALTER TABLE messages ADD COLUMN provider_message_id VARCHAR(255) DEFAULT NULL, ADD INDEX (provider_message_id)");
            file_put_contents($schema_lock_ticks, date('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1060) { // Duplicate column
                file_put_contents($schema_lock_ticks, date('Y-m-d H:i:s'));
            } else {
                file_put_contents(__DIR__ . '/../db_migration_error.log', date('Y-m-d H:i:s') . " - MsgID Migration Failed: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
    }
    // -----------------------------------------------------------

    // --- Schema Auto-Fix: Ensure user_id column exists in templates ---
    $schema_lock_template_user = __DIR__ . '/schema_template_userid.lock';
    if (!file_exists($schema_lock_template_user)) {
        try {
            // Add user_id column and an index for performance
            $pdo->exec("ALTER TABLE message_templates ADD COLUMN user_id INT NULL, ADD INDEX (user_id)");
            // Mark as done on success
            file_put_contents($schema_lock_template_user, date('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            // If column already exists (error 1060), mark as done
            if ($e->errorInfo[1] == 1060) {
                file_put_contents($schema_lock_template_user, date('Y-m-d H:i:s'));
            } else {
                // Log other errors but allow the app to continue
                file_put_contents(__DIR__ . '/../db_migration_error.log', date('Y-m-d H:i:s') . " - Template UserID Migration Failed: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
    }

    // --- Schema Auto-Fix: Ensure closed_by and closed_at columns exist in conversations ---
    $schema_lock_closed_cols = __DIR__ . '/schema_conversation_closed_cols.lock';
    if (!file_exists($schema_lock_closed_cols)) {
        try {
            // Add columns if not exist
            $pdo->exec("ALTER TABLE conversations ADD COLUMN closed_by INT NULL, ADD COLUMN closed_at DATETIME NULL");
            // Mark as done on success
            file_put_contents($schema_lock_closed_cols, date('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            // If column already exists (error 1060), mark as done
            if ($e->errorInfo[1] == 1060) {
                file_put_contents($schema_lock_closed_cols, date('Y-m-d H:i:s'));
            } else {
                // Log other errors but allow the app to continue
                file_put_contents(__DIR__ . '/../db_migration_error.log', date('Y-m-d H:i:s') . " - Conversation Closed Columns Migration Failed: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
    }

    // --- Schema Auto-Fix: Ensure is_active column exists in workflows ---
    $schema_lock_workflow_active = __DIR__ . '/schema_workflow_active.lock';
    if (!file_exists($schema_lock_workflow_active)) {
        try {
            // Add column if not exists (Default 0 = Draft)
            $pdo->exec("ALTER TABLE workflows ADD COLUMN is_active TINYINT(1) DEFAULT 0");
            file_put_contents($schema_lock_workflow_active, date('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1060) {
                file_put_contents($schema_lock_workflow_active, date('Y-m-d H:i:s'));
            } else {
                file_put_contents(__DIR__ . '/../db_migration_error.log', date('Y-m-d H:i:s') . " - Workflow Active Column Migration Failed: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
    }

    // --- Schema Auto-Fix: Ensure workflow_state column exists in conversations ---
    // Used to pause execution at Question nodes and resume on user reply.
    $schema_lock_workflow_state = __DIR__ . '/schema_conversation_workflow_state.lock';
    if (!file_exists($schema_lock_workflow_state)) {
        try {
            // Add column if not exists (Stores JSON: {active_node_id: "xyz", workflow_id: 123})
            $pdo->exec("ALTER TABLE conversations ADD COLUMN workflow_state TEXT NULL");
            file_put_contents($schema_lock_workflow_state, date('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1060) {
                file_put_contents($schema_lock_workflow_state, date('Y-m-d H:i:s'));
            } else {
                file_put_contents(__DIR__ . '/../db_migration_error.log', date('Y-m-d H:i:s') . " - Workflow State Column Migration Failed: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
    }

    // --- Schema Auto-Fix: Ensure message_type and interactive_data columns exist in messages ---
    $schema_lock_msg_interactive = __DIR__ . '/schema_message_interactive.lock';
    if (!file_exists($schema_lock_msg_interactive)) {
        try {
            // Add columns for rich messages
            $pdo->exec("ALTER TABLE messages ADD COLUMN message_type VARCHAR(50) DEFAULT 'text', ADD COLUMN interactive_data JSON NULL");
            file_put_contents($schema_lock_msg_interactive, date('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1060) {
                file_put_contents($schema_lock_msg_interactive, date('Y-m-d H:i:s'));
            } else {
                file_put_contents(__DIR__ . '/../db_migration_error.log', date('Y-m-d H:i:s') . " - Message Interactive Columns Migration Failed: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
    }

    // --- Schema Auto-Fix: Ensure header_type and buttons_data columns exist in message_templates ---
    // Used for dynamic URL buttons and media headers in templates
    $schema_lock_template_complex = __DIR__ . '/schema_template_complex_v2.lock';
    if (!file_exists($schema_lock_template_complex)) {
        try {
            // Add columns if not exist
            $pdo->exec("ALTER TABLE message_templates ADD COLUMN header_type VARCHAR(50) DEFAULT NULL, ADD COLUMN buttons_data TEXT DEFAULT NULL");
            file_put_contents($schema_lock_template_complex, date('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1060) {
                file_put_contents($schema_lock_template_complex, date('Y-m-d H:i:s'));
            } else {
                // Ignore "duplicate column" if one succeeded and other failed, just log
                file_put_contents(__DIR__ . '/../db_migration_error.log', date('Y-m-d H:i:s') . " - Template Complex Columns Migration Failed: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
    }

    // --- Schema Auto-Fix: Ensure language column exists in message_templates ---
    $schema_lock_template_lang = __DIR__ . '/schema_template_language.lock';
    if (!file_exists($schema_lock_template_lang)) {
        try {
            // Add column if not exists
            $pdo->exec("ALTER TABLE message_templates ADD COLUMN language VARCHAR(10) DEFAULT 'en_US'");
            file_put_contents($schema_lock_template_lang, date('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1060) {
                file_put_contents($schema_lock_template_lang, date('Y-m-d H:i:s'));
            } else {
                file_put_contents(__DIR__ . '/../db_migration_error.log', date('Y-m-d H:i:s') . " - Template Language Column Migration Failed: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
    }

    // --- Schema Auto-Fix: Refactor Workflows (Linear Engine) ---
    $schema_lock_workflows_refactor = __DIR__ . '/schema_workflows_refactor_v1.lock';
    if (!file_exists($schema_lock_workflows_refactor)) {
        try {
            // 1. Alter workflows table
            try { $pdo->exec("ALTER TABLE workflows ADD COLUMN trigger_type VARCHAR(50) DEFAULT 'KEYWORD'"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE workflows ADD COLUMN keywords TEXT NULL"); } catch (Exception $e) {}

            // 2. Create workflow_steps table
            $pdo->exec("CREATE TABLE IF NOT EXISTS workflow_steps (
                id INT AUTO_INCREMENT PRIMARY KEY,
                workflow_id INT NOT NULL,
                step_order INT NOT NULL DEFAULT 1,
                action_type ENUM('SEND_MESSAGE', 'ASSIGN_AGENT', 'ADD_TAG', 'ASK_QUESTION', 'DELAY') NOT NULL,
                content TEXT NULL,
                meta_data JSON NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // 2.1 Alter table to add DELAY if missing (Schema evolution)
            try {
                $pdo->exec("ALTER TABLE workflow_steps MODIFY COLUMN action_type ENUM('SEND_MESSAGE', 'ASSIGN_AGENT', 'ADD_TAG', 'ASK_QUESTION', 'DELAY') NOT NULL");
            } catch (Exception $e) {}

            // 3. Indexes
            try { $pdo->exec("CREATE INDEX idx_workflow_steps_workflow_id ON workflow_steps(workflow_id)"); } catch (Exception $e) {}
            try { $pdo->exec("CREATE INDEX idx_workflow_steps_order ON workflow_steps(step_order)"); } catch (Exception $e) {}

            file_put_contents($schema_lock_workflows_refactor, date('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            file_put_contents(__DIR__ . '/../db_migration_error.log', date('Y-m-d H:i:s') . " - Workflows Refactor Failed: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }

    // --- Schema Auto-Fix: Add DELAY to action_type ENUM ---
    // This is separated because previous migration might have run without DELAY
    $schema_lock_workflow_enum = __DIR__ . '/schema_workflow_enum_delay.lock';
    if (!file_exists($schema_lock_workflow_enum)) {
        try {
            $pdo->exec("ALTER TABLE workflow_steps MODIFY COLUMN action_type ENUM('SEND_MESSAGE', 'ASSIGN_AGENT', 'ADD_TAG', 'ASK_QUESTION', 'DELAY') NOT NULL");
            file_put_contents($schema_lock_workflow_enum, date('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            // Ignore if already done or fails safely
            file_put_contents(__DIR__ . '/../db_migration_error.log', date('Y-m-d H:i:s') . " - Workflow Enum Fix Failed: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }

    // --- Schema Auto-Fix: Add theme column to users ---
    // Allows user-specific theme preference (Light/Dark)
    $schema_lock_user_theme = __DIR__ . '/schema_user_theme.lock';
    if (!file_exists($schema_lock_user_theme)) {
        try {
            // Add column if not exists
            $pdo->exec("ALTER TABLE users ADD COLUMN theme VARCHAR(10) DEFAULT 'light'");
            file_put_contents($schema_lock_user_theme, date('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1060) {
                file_put_contents($schema_lock_user_theme, date('Y-m-d H:i:s'));
            } else {
                file_put_contents(__DIR__ . '/../db_migration_error.log', date('Y-m-d H:i:s') . " - User Theme Column Migration Failed: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
    }

    // --- SAAS ARCHITECTURE MIGRATION (PHASE 1) ---
    $saas_migration_lock = __DIR__ . '/../migrations/saas_migration_001.lock';
    if (!file_exists($saas_migration_lock)) {
        // Include the migration script
        // Note: The script handles its own transaction and error logging
        require_once __DIR__ . '/../migrations/saas_migration_001.php';
    }

} catch (PDOException $e) {
    // Ikishindikana, toa ujumbe wa kosa katika format ya JSON
    header('Content-Type: application/json');
    http_response_code(500); // Internal Server Error
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    // Sitisha utekelezaji
    die();
}
?>
