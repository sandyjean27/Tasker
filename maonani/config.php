<?php
// config.php
session_set_cookie_params(86400); // Must be before session_start()
ini_set('session.gc_maxlifetime', 86400);

$host = "localhost";
$dbname = "accountant";
$db_username = "root";
$db_password = "";