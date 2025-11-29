<?php
// verification/verify_backend_taxes.php
require_once 'api/config.php';
require_once 'api/db.php';

session_start();
// Mock user session
$_SESSION['user_id'] = 1;

include 'api/get_dashboard_stats.php';
