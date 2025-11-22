<?php
// api/logout.php

// Anzisha session ili tuweze kuifuta
session_start();

// Futa vigezo vyote vya session
$_SESSION = array();

// Futa session yenyewe
session_destroy();

// Mpeleke mtumiaji kwenye ukurasa wa kuingia (login page)
header("Location: ../login.php");
exit();
?>
