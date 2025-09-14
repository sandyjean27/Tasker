<?php
session_start();
require_once 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Message ID not provided']);
    exit;
}

$message_id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'];

// Get message thread
$thread_query = "WITH RECURSIVE message_thread AS (
    -- Get the initial message and all its replies
    SELECT m.*, 1 as depth
    FROM messages m
    WHERE m.id = ? OR m.parent_id = ?
    
    UNION ALL
    
    -- Get parent messages recursively
    SELECT m.*, mt.depth + 1
    FROM messages m
    JOIN message_thread mt ON m.id = mt.parent_id
)
SELECT DISTINCT 
    mt.*,
    u1.first_name as sender_first_name, 
    u1.last_name as sender_last_name,
    u2.first_name as receiver_first_name, 
    u2.last_name as receiver_last_name
FROM message_thread mt
JOIN users u1 ON mt.sender_id = u1.id
JOIN users u2 ON mt.receiver_id = u2.id
ORDER BY mt.created_at ASC";

$thread_stmt = $conn->prepare($thread_query);
$thread_stmt->bind_param("ii", $message_id, $message_id);
$thread_stmt->execute();
$thread_result = $thread_stmt->get_result();

$thread = [];
while ($message = $thread_result->fetch_assoc()) {
    // Get attachments for this message
    $att_query = "SELECT * FROM message_attachments WHERE message_id = ?";
    $att_stmt = $conn->prepare($att_query);
    $att_stmt->bind_param("i", $message['id']);
    $att_stmt->execute();
    $attachments = $att_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $thread[] = [
        'id' => $message['id'],
        'subject' => htmlspecialchars($message['subject']),
        'message' => nl2br(htmlspecialchars($message['message'])),
        'sender_id' => $message['sender_id'],
        'receiver_id' => $message['receiver_id'],
        'sender_name' => htmlspecialchars($message['sender_first_name'] . ' ' . $message['sender_last_name']),
        'receiver_name' => htmlspecialchars($message['receiver_first_name'] . ' ' . $message['receiver_last_name']),
        'created_at' => date('M d, Y h:i A', strtotime($message['created_at'])),
        'attachments' => $attachments
    ];
}

$thread_stmt->close();

// Mark message as read
$read_stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE id = ? AND receiver_id = ?");
$read_stmt->bind_param("ii", $message_id, $user_id);
$read_stmt->execute();
$read_stmt->close();

echo json_encode([
    'success' => true,
    'thread' => $thread
]); 