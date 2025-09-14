<?php
session_start();
require_once 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$notification_id = $data['notification_id'] ?? 0;
$user_id = $_SESSION['user_id'];

if ($notification_id) {
    $query = "UPDATE notifications 
              SET `read` = 1 
              WHERE id = ? AND user_id = ?";
              
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $notification_id, $user_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update notification']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid notification ID']);
}
?>