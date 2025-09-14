<?php
session_start();
require_once 'db_connect.php';

// Get notifications
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $conn->prepare("
        SELECT n.*, 
        t.title as task_title,
        m.content as message_content
        FROM notifications n
        LEFT JOIN tasks t ON n.related_id = t.id AND n.type = 'task_assigned'
        LEFT JOIN messages m ON n.related_id = m.id AND n.type = 'message'
        WHERE n.user_id = ?
        ORDER BY n.created_at DESC
    ");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        $notifications[] = [
            'id' => $row['id'],
            'type' => $row['type'],
            'message' => $row['message'],
            'read' => (bool)$row['read'],
            'time' => date('M j, g:i a', strtotime($row['created_at'])),
            'meta' => [
                'task_title' => $row['task_title'],
                'message_content' => $row['message_content']
            ]
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode($notifications);
    exit;
}

// Mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (isset($data['markRead'])) {
        $stmt = $conn->prepare("UPDATE notifications SET read = 1 WHERE id = ?");
        $stmt->bind_param("i", $data['id']);
        $stmt->execute();
    }
    
    exit;
}