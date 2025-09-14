<?php
require_once 'db_connection.php';
require_once 'task_notifications.php';

// Check for tasks due soon and create notifications
notifyTaskDue();

// Clean up old notifications
$cleanup_query = "DELETE FROM notifications 
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) 
                 AND `read` = 1";
$conn->query($cleanup_query);
?> 