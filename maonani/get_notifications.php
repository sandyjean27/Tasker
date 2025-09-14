<?php
session_start();
require_once 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit();
}

$user_id = $_SESSION['user_id'];

// Get notifications with related information
$query = "SELECT n.*, 
          t.title as task_title,
          t.description as task_description,
          t.due_date as task_due_date,
          u.first_name as sender_first_name,
          u.last_name as sender_last_name
          FROM notifications n
          LEFT JOIN tasks t ON n.task_id = t.id
          LEFT JOIN users u ON n.sender_id = u.id
          WHERE n.user_id = ?
          ORDER BY n.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = [
        'id' => $row['id'],
        'type' => $row['type'],
        'message' => $row['message'],
        'created_at' => $row['created_at'],
        'read' => (bool)$row['read'],
        'task_id' => $row['task_id'],
        'task_title' => $row['task_title'],
        'task_description' => $row['task_description'],
        'task_due_date' => $row['task_due_date'],
        'sender_name' => $row['sender_first_name'] . ' ' . $row['sender_last_name']
    ];
}

header('Content-Type: application/json');
echo json_encode($notifications);
?> 