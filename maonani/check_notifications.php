<?php
session_start();
$host = "localhost"; 
$dbname = "accountant"; 
$username = "root"; 
$password = ""; 

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

$user_id = $_SESSION['user_id'];

// Get unread notifications count
$notifications_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
$notify_stmt = $conn->prepare($notifications_query);
$notify_stmt->bind_param("i", $user_id);
$notify_stmt->execute();
$notify_result = $notify_stmt->get_result();
$notify_count = $notify_result->fetch_assoc()['count'];
$notify_stmt->close();

// Send JSON response
header('Content-Type: application/json');
echo json_encode(['count' => $notify_count]); 