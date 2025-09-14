<?php
session_start();
require_once 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['count' => 0]);
    exit();
}

$user_id = $_SESSION['user_id'];

// Get count of unread notifications
$query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND `read` = 0";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$count = $result->fetch_assoc()['count'];

header('Content-Type: application/json');
echo json_encode(['count' => $count]);
?>
