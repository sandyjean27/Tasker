<?php
session_start();
require_once 'db_connection.php';

function createTaskNotification($user_id, $type, $message, $task_id, $sender_id = null) {
    global $conn;
    
    if (!$sender_id && isset($_SESSION['user_id'])) {
        $sender_id = $_SESSION['user_id'];
    }
    
    $query = "INSERT INTO notifications (user_id, type, message, task_id, sender_id, created_at) 
              VALUES (?, ?, ?, ?, ?, NOW())";
              
    $stmt = $conn->prepare($query);
    $stmt->bind_param("issii", $user_id, $type, $message, $task_id, $sender_id);
    
    return $stmt->execute();
}

// Function to notify task creator and assignee
function notifyTaskUsers($task_id, $type, $message) {
    global $conn;
    
    // Get task details including creator and assignee
    $query = "SELECT created_by, assigned_to FROM tasks WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $task_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $task = $result->fetch_assoc();
    
    if ($task) {
        // Notify creator if they're not the current user
        if ($task['created_by'] != $_SESSION['user_id']) {
            createTaskNotification($task['created_by'], $type, $message, $task_id);
        }
        
        // Notify assignee if they're not the current user and not the creator
        if ($task['assigned_to'] != $_SESSION['user_id'] && $task['assigned_to'] != $task['created_by']) {
            createTaskNotification($task['assigned_to'], $type, $message, $task_id);
        }
    }
}

// Function to create reminder notifications
function createTaskReminders() {
    global $conn;
    
    // Get tasks due within the next 24 hours that haven't had a reminder sent
    $query = "SELECT t.*, u.first_name, u.last_name 
              FROM tasks t
              JOIN users u ON t.assigned_to = u.id
              WHERE t.status = 'pending' 
              AND t.due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)
              AND NOT EXISTS (
                  SELECT 1 FROM notifications n 
                  WHERE n.task_id = t.id 
                  AND n.type = 'task_reminder'
                  AND n.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
              )";
              
    $result = $conn->query($query);
    
    while ($task = $result->fetch_assoc()) {
        $message = "Reminder: Task '{$task['title']}' is due " . date('M j, Y g:i A', strtotime($task['due_date']));
        createTaskNotification($task['assigned_to'], 'task_reminder', $message, $task['id']);
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create_notification':
            $user_id = $_POST['user_id'] ?? 0;
            $type = $_POST['type'] ?? '';
            $message = $_POST['message'] ?? '';
            $task_id = $_POST['task_id'] ?? 0;
            
            if ($user_id && $type && $message && $task_id) {
                $success = createTaskNotification($user_id, $type, $message, $task_id);
                echo json_encode(['success' => $success]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Missing parameters']);
            }
            break;
            
        case 'notify_task_users':
            $task_id = $_POST['task_id'] ?? 0;
            $type = $_POST['type'] ?? '';
            $message = $_POST['message'] ?? '';
            
            if ($task_id && $type && $message) {
                notifyTaskUsers($task_id, $type, $message);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Missing parameters']);
            }
            break;
    }
}
?> 