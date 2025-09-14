<?php
session_start();
require_once 'db_connection.php';
require_once 'task_notifications.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $task_id = $_POST['task_id'] ?? 0;
    $new_status = $_POST['status'] ?? '';
    $user_id = $_SESSION['user_id'] ?? 0;
    
    if ($task_id && $new_status && $user_id) {
        // Get task details
        $task_query = "SELECT t.*, u.first_name as creator_name 
                      FROM tasks t 
                      JOIN users u ON t.created_by = u.id 
                      WHERE t.id = ?";
        $stmt = $conn->prepare($task_query);
        $stmt->bind_param("i", $task_id);
        $stmt->execute();
        $task = $stmt->get_result()->fetch_assoc();
        
        if ($task) {
            // Update task status
            $update_query = "UPDATE tasks SET status = ?, updated_at = NOW() WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $new_status, $task_id);
            
            if ($update_stmt->execute()) {
                // Create notification based on status change
                $message = '';
                if ($new_status === 'completed') {
                    $message = "Task '{$task['title']}' has been completed";
                } else if ($new_status === 'pending') {
                    $message = "Task '{$task['title']}' has been reopened";
                }
                
                // Notify task creator
                if ($task['created_by'] != $user_id) {
                    createTaskNotification(
                        $task['created_by'],
                        'task_status_change',
                        $message,
                        $task_id,
                        $user_id
                    );
                }
                
                // Notify assigned user if different from creator and current user
                if ($task['assigned_to'] != $user_id && $task['assigned_to'] != $task['created_by']) {
                    createTaskNotification(
                        $task['assigned_to'],
                        'task_status_change',
                        $message,
                        $task_id,
                        $user_id
                    );
                }
                
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update task status']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Task not found']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    }
}
?> 