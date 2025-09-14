<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$host = "localhost"; 
$dbname = "accountant";
$username = "root"; 
$password = ""; 

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

if (!isset($_POST['message_id'])) {
    echo json_encode(['success' => false, 'message' => 'Message ID not provided']);
    exit;
}

$message_id = (int)$_POST['message_id'];

// Update message status
$resolve_stmt = $conn->prepare("UPDATE messages SET is_resolved = 1 WHERE id = ? AND (message_type = 'concern' OR message_type = 'reminder')");
$resolve_stmt->bind_param("i", $message_id);

if ($resolve_stmt->execute()) {
    // Get sender information
    $get_sender = $conn->prepare("SELECT sender_id FROM messages WHERE id = ?");
    $get_sender->bind_param("i", $message_id);
    $get_sender->execute();
    $sender_result = $get_sender->get_result();
    $sender = $sender_result->fetch_assoc();
    
    if ($sender) {
        // Create notification for the sender
        $notify_stmt = $conn->prepare("INSERT INTO notifications (user_id, message_id, type, message) VALUES (?, ?, 'resolved', 'Your message has been marked as resolved')");
        $notify_stmt->bind_param("ii", $sender['sender_id'], $message_id);
        $notify_stmt->execute();
        $notify_stmt->close();
    }
    
    $get_sender->close();
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Error updating message status']);
}

$resolve_stmt->close();
$conn->close();
?> 