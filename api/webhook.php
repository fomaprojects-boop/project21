<?php
// api/webhook.php
// This file delegates to the primary webhook handler in the root directory.
// This ensures a single source of truth while maintaining compatibility if endpoints point here.

require_once __DIR__ . '/../webhook.php';
?>
