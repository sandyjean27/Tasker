<?php
session_start();
require_once 'db_connection.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

if (isset($_GET['id'])) {
    $staff_id = (int)$_GET['id'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // First reassign or delete all related records
        
        // Update tasks
        $reassign_tasks = $conn->prepare("UPDATE tasks SET assigned_to = ? WHERE assigned_to = ?");
        $admin_id = $_SESSION['user_id']; // Reassign to current admin
        $reassign_tasks->bind_param("ii", $admin_id, $staff_id);
        $reassign_tasks->execute();
        
        // Delete notifications
        $delete_notifications = $conn->prepare("DELETE FROM notifications WHERE user_id = ? OR sender_id = ?");
        $delete_notifications->bind_param("ii", $staff_id, $staff_id);
        $delete_notifications->execute();
        
        // Delete messages
        $delete_messages = $conn->prepare("DELETE FROM messages WHERE sender_id = ? OR receiver_id = ?");
        $delete_messages->bind_param("ii", $staff_id, $staff_id);
        $delete_messages->execute();
        
        // Delete task submissions
        $delete_submissions = $conn->prepare("DELETE FROM task_submissions WHERE submitted_by = ? OR reviewed_by = ?");
        $delete_submissions->bind_param("ii", $staff_id, $staff_id);
        $delete_submissions->execute();
        
        // Finally delete the user
        $delete_user = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'staff'");
        $delete_user->bind_param("i", $staff_id);
        $delete_user->execute();
        
        // If everything is successful, commit the transaction
        $conn->commit();
        $_SESSION['success_message'] = "Staff member deleted successfully.";
        
    } catch (Exception $e) {
        // If there's an error, rollback the changes
        $conn->rollback();
        $_SESSION['error_message'] = "Could not delete staff member: " . $e->getMessage();
    }
}

// Redirect back to manage staff page
header("Location: manage_staff.php");
exit();
?> 