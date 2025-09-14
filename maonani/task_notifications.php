<?php
// Only start session if one hasn't been started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connection
require_once __DIR__ . '/db_connection.php';

function createTaskNotification($user_id, $type, $message, $task_id = null, $sender_id = null) {
    global $conn;
    
    if (!$sender_id && isset($_SESSION['user_id'])) {
        $sender_id = $_SESSION['user_id'];
    }
    
    $query = "INSERT INTO notifications (user_id, type, message, task_id, sender_id, created_at) 
              VALUES (?, ?, ?, ?, ?, NOW())";
              
    $stmt = $conn->prepare($query);
    $stmt->bind_param("isiii", $user_id, $type, $message, $task_id, $sender_id);
    
    return $stmt->execute();
}

function notifyAdmin($type, $message, $task_id = null, $sender_id = null) {
    global $conn;
    
    // Get admin user ID
    $query = "SELECT id FROM users WHERE role = 'admin' LIMIT 1";
    $result = $conn->query($query);
    if ($admin = $result->fetch_assoc()) {
        return createTaskNotification($admin['id'], $type, $message, $task_id, $sender_id);
    }
    return false;
}

function notifyTaskDue() {
    global $conn;
    
    // Get tasks due within 24 hours
    $query = "SELECT t.*, u.id as user_id, u.first_name, u.last_name 
              FROM tasks t 
              JOIN users u ON t.assigned_to = u.id 
              WHERE t.status = 'pending' 
              AND t.due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)
              AND NOT EXISTS (
                  SELECT 1 FROM notifications n 
                  WHERE n.task_id = t.id 
                  AND n.type = 'task_due_soon' 
                  AND n.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
              )";
              
    $result = $conn->query($query);
    while ($task = $result->fetch_assoc()) {
        // Notify assigned user
        $message = "Your task '{$task['title']}' is due soon";
        createTaskNotification($task['user_id'], 'task_due_soon', $message, $task['id']);
        
        // Notify admin
        $message = "Task '{$task['title']}' assigned to {$task['first_name']} {$task['last_name']} is due soon";
        notifyAdmin('task_due_soon', $message, $task['id']);
    }
}

function notifyTaskSubmission($task_id, $submitted_by) {
    global $conn;
    
    // Get task info
    $query = "SELECT t.title, t.created_by, u.first_name, u.last_name 
              FROM tasks t 
              JOIN users u ON t.assigned_to = u.id 
              WHERE t.id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $task_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($task = $result->fetch_assoc()) {
        // Notify task creator
        $message = "Task '{$task['title']}' has been submitted by {$task['first_name']} {$task['last_name']}";
        return createTaskNotification($task['created_by'], 'task_submitted', $message, $task_id, $submitted_by);
    }
    
    return false;
}

function notifyTaskApproved($task_id, $admin_id) {
    global $conn;
    
    // Get task info
    $query = "SELECT t.title, t.assigned_to, u.first_name, u.last_name 
              FROM tasks t 
              JOIN users u ON u.id = ?
              WHERE t.id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $admin_id, $task_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($task = $result->fetch_assoc()) {
        // Notify assigned user
        $message = "Your submission for task '{$task['title']}' has been approved by {$task['first_name']} {$task['last_name']}";
        return createTaskNotification($task['assigned_to'], 'task_approved', $message, $task_id, $admin_id);
    }
    
    return false;
}

function notifyTaskResubmission($task_id, $admin_id) {
    global $conn;
    
    // Get task info
    $query = "SELECT t.title, t.assigned_to, u.first_name, u.last_name 
              FROM tasks t 
              JOIN users u ON u.id = ?
              WHERE t.id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $admin_id, $task_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($task = $result->fetch_assoc()) {
        // Notify assigned user
        $message = "Your submission for task '{$task['title']}' requires changes. Please review and resubmit.";
        return createTaskNotification($task['assigned_to'], 'task_resubmit', $message, $task_id, $admin_id);
    }
    
    return false;
}

function notifyNewMessage($sender_id, $receiver_id, $message_id) {
    global $conn;
    
    // Get sender details
    $query = "SELECT first_name, last_name FROM users WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $sender_id);
    $stmt->execute();
    $sender = $stmt->get_result()->fetch_assoc();
    
    $message = "New message from {$sender['first_name']} {$sender['last_name']}";
    createTaskNotification($receiver_id, 'new_message', $message, null, $sender_id);
}

function getTaskNotifications($user_id) {
    global $conn;
    
    $query = "SELECT n.*, t.title as task_title, u.first_name as sender_name 
              FROM notifications n 
              LEFT JOIN tasks t ON n.task_id = t.id 
              LEFT JOIN users u ON n.sender_id = u.id 
              WHERE n.user_id = ? 
              ORDER BY n.created_at DESC";
              
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notifications = array();
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    
    return $notifications;
}

function markNotificationAsRead($notification_id, $user_id) {
    global $conn;
    
    $query = "UPDATE notifications SET `read` = 1 
              WHERE id = ? AND user_id = ?";
              
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $notification_id, $user_id);
    
    return $stmt->execute();
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create_notification':
            $user_id = $_POST['user_id'] ?? 0;
            $type = $_POST['type'] ?? '';
            $message = $_POST['message'] ?? '';
            $task_id = $_POST['task_id'] ?? null;
            
            if ($user_id && $type && $message) {
                $success = createTaskNotification($user_id, $type, $message, $task_id);
                echo json_encode(['success' => $success]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Missing parameters']);
            }
            break;
            
        case 'notify_task_submission':
            $task_id = $_POST['task_id'] ?? 0;
            $submitted_by = $_SESSION['user_id'] ?? 0;
            
            if ($task_id && $submitted_by) {
                $success = notifyTaskSubmission($task_id, $submitted_by);
                echo json_encode(['success' => $success]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Missing parameters']);
            }
            break;
            
        case 'notify_new_message':
            $receiver_id = $_POST['receiver_id'] ?? 0;
            $message_id = $_POST['message_id'] ?? 0;
            $sender_id = $_SESSION['user_id'] ?? 0;
            
            if ($receiver_id && $message_id && $sender_id) {
                $success = notifyNewMessage($sender_id, $receiver_id, $message_id);
                echo json_encode(['success' => $success]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Missing parameters']);
            }
            break;
            
        case 'check_due_tasks':
            notifyTaskDue();
            echo json_encode(['success' => true]);
            break;
            
        case 'get_notifications':
            if (isset($_SESSION['user_id'])) {
                $notifications = getTaskNotifications($_SESSION['user_id']);
                echo json_encode($notifications);
            } else {
                echo json_encode(['error' => 'User not authenticated']);
            }
            break;
            
        case 'mark_read':
            if (isset($_SESSION['user_id']) && isset($_POST['notification_id'])) {
                $success = markNotificationAsRead($_POST['notification_id'], $_SESSION['user_id']);
                echo json_encode(['success' => $success]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
            }
            break;
            
        case 'notify_task_approved':
            $task_id = $_POST['task_id'] ?? 0;
            $admin_id = $_SESSION['user_id'] ?? 0;
            
            if ($task_id && $admin_id) {
                $success = notifyTaskApproved($task_id, $admin_id);
                echo json_encode(['success' => $success]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Missing parameters']);
            }
            break;
            
        case 'notify_task_resubmission':
            $task_id = $_POST['task_id'] ?? 0;
            $admin_id = $_SESSION['user_id'] ?? 0;
            
            if ($task_id && $admin_id) {
                $success = notifyTaskResubmission($task_id, $admin_id);
                echo json_encode(['success' => $success]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Missing parameters']);
            }
            break;
    }
}
?> 