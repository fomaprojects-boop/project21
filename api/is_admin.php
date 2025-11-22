<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$is_admin = false;
if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'Admin') {
    $is_admin = true;
}
