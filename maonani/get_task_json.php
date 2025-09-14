<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'No task ID provided']);
    exit();
}

$task_id = (int)$_GET['id'];

// Database connection
$host = "localhost";
$dbname = "accountant";
$db_username = "root";
$db_password = "";

$conn = new mysqli($host, $db_username, $db_password, $dbname);

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get task details with user information
$query = "SELECT t.*, 
          CONCAT(u1.first_name, ' ', u1.last_name) as creator_name,
          CONCAT(u2.first_name, ' ', u2.last_name) as assigned_name
          FROM tasks t
          LEFT JOIN users u1 ON t.created_by = u1.id
          LEFT JOIN users u2 ON t.assigned_to = u2.id
          WHERE t.id = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $task_id);
$stmt->execute();
$result = $stmt->get_result();
$task = $result->fetch_assoc();

if (!$task) {
    echo json_encode(['success' => false, 'message' => 'Task not found']);
    exit();
}

// Get task attachments
$attachments_query = "SELECT * FROM task_attachments WHERE task_id = ?";
$attachments_stmt = $conn->prepare($attachments_query);
$attachments_stmt->bind_param("i", $task_id);
$attachments_stmt->execute();
$attachments_result = $attachments_stmt->get_result();

$attachments = [];
while ($attachment = $attachments_result->fetch_assoc()) {
    $attachments[] = [
        'id' => $attachment['id'],
        'file_name' => $attachment['file_name'],
        'file_path' => $attachment['file_path']
    ];
}

// Format the response
$response = [
    'success' => true,
    'task' => [
        'id' => $task['id'],
        'title' => $task['title'],
        'description' => $task['description'],
        'status' => $task['status'],
        'priority' => $task['priority'],
        'due_date' => $task['due_date'],
        'created_at' => $task['created_at'],
        'completed_at' => $task['completed_at'],
        'completion_comments' => $task['completion_comments'],
        'creator_name' => $task['creator_name'],
        'assigned_name' => $task['assigned_name'],
        'attachments' => $attachments
    ]
];

echo json_encode($response);
?>
