<?php
require_once 'db_connection.php';
require_once 'create_task_notification.php';

// Create task reminders
createTaskReminders();

// Clean up old notifications (optional, keep last 30 days)
$cleanup_query = "DELETE FROM notifications 
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) 
                 AND `read` = 1";
$conn->query($cleanup_query);
?> 