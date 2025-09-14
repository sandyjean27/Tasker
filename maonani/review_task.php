<?php
session_start();
require_once 'db_connection.php';
require_once 'task_notifications.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Check if task ID is provided
if (!isset($_GET['id'])) {
    header("Location: manage_tasks.php");
    exit();
}

$task_id = (int)$_GET['id'];

// Get task details with user information and submissions
$query = "SELECT t.*, 
          u1.first_name as creator_first_name, u1.last_name as creator_last_name,
          u2.first_name as assigned_first_name, u2.last_name as assigned_last_name,
          GROUP_CONCAT(DISTINCT ta.id ORDER BY ta.id SEPARATOR '||') as attachment_ids,
          GROUP_CONCAT(DISTINCT ta.file_name ORDER BY ta.id SEPARATOR '||') as attachment_names,
          GROUP_CONCAT(DISTINCT ta.file_path ORDER BY ta.id SEPARATOR '||') as attachment_paths,
          (SELECT COUNT(*) FROM task_submissions WHERE task_id = t.id) as submission_count
          FROM tasks t
          LEFT JOIN users u1 ON t.created_by = u1.id
          LEFT JOIN users u2 ON t.assigned_to = u2.id
          LEFT JOIN task_attachments ta ON t.id = ta.task_id
          WHERE t.id = ?
          GROUP BY t.id";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $task_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error_message'] = "Task not found.";
    header("Location: manage_tasks.php");
    exit();
}

$task = $result->fetch_assoc();

// Get submissions for this task
$submissions_query = "SELECT ts.*, u.first_name, u.last_name 
                     FROM task_submissions ts
                     JOIN users u ON ts.submitted_by = u.id
                     WHERE ts.task_id = ?
                     ORDER BY ts.submitted_at DESC";
$submissions_stmt = $conn->prepare($submissions_query);
$submissions_stmt->bind_param("i", $task_id);
$submissions_stmt->execute();
$submissions_result = $submissions_stmt->get_result();

// Handle task approval
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST['approve_task'])) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Update task status
            $update_task = $conn->prepare("UPDATE tasks SET status = 'completed', completion_notes = ?, completed_at = NOW() WHERE id = ?");
            $notes = isset($_POST['admin_notes']) ? $_POST['admin_notes'] : '';
            $update_task->bind_param("si", $notes, $task_id);
            $update_task->execute();
            
            // Update submission status if there are any
            if ($task['submission_count'] > 0) {
                $update_submission = $conn->prepare("UPDATE task_submissions SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_notes = ? WHERE task_id = ? AND status = 'pending' ORDER BY submitted_at DESC LIMIT 1");
                $update_submission->bind_param("isi", $_SESSION['user_id'], $notes, $task_id);
                $update_submission->execute();
            }
            
            // Send notification to the assigned user
            notifyTaskApproved($task_id, $_SESSION['user_id']);
            
            // Send email notification to the employee
            try {
                $employee_email_query = "SELECT email FROM users WHERE id = ?";
                $email_stmt = $conn->prepare($employee_email_query);
                
                if ($email_stmt) {
                    $email_stmt->bind_param("i", $task['assigned_to']);
                    $email_stmt->execute();
                    $email_result = $email_stmt->get_result();
                    
                    if ($email_result && $email_result->num_rows > 0) {
                        $employee_email = $email_result->fetch_assoc()['email'];
                        $task_title = htmlspecialchars($task['title']);
                        $admin_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Admin';
                        
                        $subject = "Task Approved: $task_title";
                        $message = "Hello {$task['assigned_first_name']},\n\n";
                        $message .= "Your task '$task_title' has been approved.\n\n";
                        if (!empty($notes)) {
                            $message .= "Admin Notes: $notes\n\n";
                        }
                        $message .= "Thank you for your work!\n\n";
                        $message .= "Regards,\nInboxSandy Team";
                        
                        // Send email
                        @mail($employee_email, $subject, $message);
                    }
                }
            } catch (Exception $e) {
                // Log error but continue with the process
                error_log("Email notification error: " . $e->getMessage());
            }
            
            $conn->commit();
            $_SESSION['success_message'] = "Task has been approved successfully. Employee has been notified.";
            header("Location: manage_tasks.php");
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = "Error approving task: " . $e->getMessage();
        }
    } elseif (isset($_POST['request_resubmission'])) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Update task status back to pending
            $update_task = $conn->prepare("UPDATE tasks SET status = 'pending' WHERE id = ?");
            $update_task->bind_param("i", $task_id);
            $update_task->execute();
            
            // Update submission status if there are any
            if ($task['submission_count'] > 0) {
                $update_submission = $conn->prepare("UPDATE task_submissions SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_notes = ? WHERE task_id = ? AND status = 'pending' ORDER BY submitted_at DESC LIMIT 1");
                $notes = isset($_POST['admin_notes']) ? $_POST['admin_notes'] : '';
                $update_submission->bind_param("isi", $_SESSION['user_id'], $notes, $task_id);
                $update_submission->execute();
            }
            
            // Send notification to the assigned user
            notifyTaskResubmission($task_id, $_SESSION['user_id']);
            
            // Send email notification to the employee
            try {
                $employee_email_query = "SELECT email FROM users WHERE id = ?";
                $email_stmt = $conn->prepare($employee_email_query);
                
                if ($email_stmt) {
                    $email_stmt->bind_param("i", $task['assigned_to']);
                    $email_stmt->execute();
                    $email_result = $email_stmt->get_result();
                    
                    if ($email_result && $email_result->num_rows > 0) {
                        $employee_email = $email_result->fetch_assoc()['email'];
                        $task_title = htmlspecialchars($task['title']);
                        $admin_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Admin';
                        
                        $subject = "Task Requires Resubmission: $task_title";
                        $message = "Hello {$task['assigned_first_name']},\n\n";
                        $message .= "Your task '$task_title' requires changes and resubmission.\n\n";
                        if (!empty($notes)) {
                            $message .= "Admin Notes: $notes\n\n";
                        }
                        $message .= "Please review the feedback and resubmit the task.\n\n";
                        $message .= "Regards,\nInboxSandy Team";
                        
                        // Send email
                        @mail($employee_email, $subject, $message);
                    }
                }
            } catch (Exception $e) {
                // Log error but continue with the process
                error_log("Email notification error: " . $e->getMessage());
            }
            
            $conn->commit();
            $_SESSION['success_message'] = "Resubmission request sent successfully. Employee has been notified.";
            header("Location: manage_tasks.php");
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = "Error requesting resubmission: " . $e->getMessage();
        }
    } elseif (isset($_POST['reopen_task'])) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Update task status
            $update_task = $conn->prepare("UPDATE tasks SET status = 'pending', completion_notes = ?, completed_at = NULL WHERE id = ?");
            $notes = isset($_POST['admin_notes']) ? $_POST['admin_notes'] : '';
            $update_task->bind_param("si", $notes, $task_id);
            $update_task->execute();
            
            // Update submission status if there are any
            if ($task['submission_count'] > 0) {
                $update_submission = $conn->prepare("UPDATE task_submissions SET status = 'pending', reviewed_by = NULL, reviewed_at = NULL, review_notes = NULL WHERE task_id = ? AND status = 'approved' ORDER BY submitted_at DESC LIMIT 1");
                $update_submission->bind_param("i", $task_id);
                $update_submission->execute();
            }
            
            // Send notification to the assigned user
            try {
                $notify_stmt = $conn->prepare("INSERT INTO notifications (user_id, task_id, type, message, sender_id) VALUES (?, ?, 'task_reopened', 'Your task has been reopened and requires attention', ?)");
                
                if ($notify_stmt) {
                    $notify_stmt->bind_param("iii", $task['assigned_to'], $task_id, $_SESSION['user_id']);
                    $notify_stmt->execute();
                }
                
                // Send email notification to the employee
                $employee_email_query = "SELECT email FROM users WHERE id = ?";
                $email_stmt = $conn->prepare($employee_email_query);
                
                if ($email_stmt) {
                    $email_stmt->bind_param("i", $task['assigned_to']);
                    $email_stmt->execute();
                    $email_result = $email_stmt->get_result();
                    
                    if ($email_result && $email_result->num_rows > 0) {
                        $employee_email = $email_result->fetch_assoc()['email'];
                        $task_title = htmlspecialchars($task['title']);
                        $admin_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Admin';
                        
                        $subject = "Task Reopened: $task_title";
                        $message = "Hello {$task['assigned_first_name']},\n\n";
                        $message .= "Your task '$task_title' has been reopened.\n\n";
                        if (!empty($notes)) {
                            $message .= "Admin Notes: $notes\n\n";
                        }
                        $message .= "Please review and take appropriate action.\n\n";
                        $message .= "Regards,\nInboxSandy Team";
                        
                        // Send email
                        @mail($employee_email, $subject, $message);
                    }
                }
            } catch (Exception $e) {
                // Log error but continue with the process
                error_log("Notification error: " . $e->getMessage());
            }
            
            $conn->commit();
            $_SESSION['success_message'] = "Task has been reopened successfully. Employee has been notified.";
            header("Location: manage_tasks.php");
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = "Error reopening task: " . $e->getMessage();
        }
    }
}

// Format due date
$due_date = date('M j, Y g:i A', strtotime($task['due_date']));
$is_overdue = strtotime($task['due_date']) < time() && $task['status'] !== 'completed';

// Check if there are attachments
$has_attachments = !empty($task['attachment_names']);
$attachment_ids = $has_attachments ? explode('||', $task['attachment_ids']) : [];
$attachment_names = $has_attachments ? explode('||', $task['attachment_names']) : [];
$attachment_paths = $has_attachments ? explode('||', $task['attachment_paths']) : [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Task - InboxSandy</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4a90e2;
            --secondary-color: #5a6268;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #3498db;
            --light-color: #f8f9fa;
            --dark-color: #2c3e50;
            --border-color: #dee2e6;
            --border-radius: 10px;
            --box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f4f7fc;
            color: var(--dark-color);
            line-height: 1.6;
        }

        .task-review-container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0;
        }

        .task-card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
            margin-bottom: 30px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: var(--primary-color);
            font-size: 16px;
            font-weight: 500;
            text-decoration: none;
            margin-bottom: 20px;
            transition: var(--transition);
        }

        .back-link:hover {
            color: #3a7bc8;
            transform: translateX(-5px);
        }

        .task-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #3178c6 100%);
            color: white;
            padding: 30px;
            position: relative;
        }

        .task-title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 15px;
            color: white;
        }

        .task-status-container {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-top: 10px;
        }

        .task-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            background-color: rgba(255, 255, 255, 0.2);
        }

        .status-pending {
            background-color: var(--warning-color);
            color: white;
        }

        .status-completed {
            background-color: var(--success-color);
            color: white;
        }

        .status-overdue {
            background-color: var(--danger-color);
            color: white;
        }

        .task-meta {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 25px;
            padding: 30px;
            border-bottom: 1px solid var(--border-color);
        }

        .meta-item {
            display: flex;
            flex-direction: column;
        }

        .meta-label {
            font-size: 14px;
            color: #7f8c8d;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .meta-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark-color);
        }

        .text-danger {
            color: var(--danger-color) !important;
        }

        .section-card {
            padding: 30px;
            border-bottom: 1px solid var(--border-color);
        }

        .section-header {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--dark-color);
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--primary-color);
        }

        .task-description {
            background-color: var(--light-color);
            padding: 25px;
            border-radius: var(--border-radius);
            margin-top: 15px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
            line-height: 1.8;
        }

        .attachments-list {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 15px;
        }

        .attachment-item {
            background-color: var(--light-color);
            padding: 15px;
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
            transition: var(--transition);
        }

        .attachment-item:hover {
            background-color: #e9ecef;
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .attachment-item i {
            font-size: 24px;
            color: var(--primary-color);
        }

        .attachment-item a {
            color: var(--dark-color);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .attachment-item a:hover {
            color: var(--primary-color);
        }

        .history-list {
            margin-top: 20px;
        }

        .history-item {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .submission-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            background-color: var(--light-color);
            border-bottom: 1px solid var(--border-color);
        }

        .submission-by {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            color: #7f8c8d;
        }

        .submission-by i {
            font-size: 18px;
            color: var(--primary-color);
        }

        .submission-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-approved {
            background-color: var(--success-color);
            color: white;
        }

        .status-rejected {
            background-color: var(--danger-color);
            color: white;
        }

        .submission-content {
            padding: 20px;
            background-color: white;
            line-height: 1.8;
        }

        .review-notes {
            margin: 0 20px 20px;
            padding: 15px;
            background-color: #fff8e1;
            border-radius: 8px;
            border-left: 4px solid var(--warning-color);
        }

        .review-notes-label {
            font-weight: 600;
            color: var(--warning-color);
            margin-bottom: 8px;
            display: block;
        }

        .review-notes-content {
            color: var(--dark-color);
            font-style: italic;
            line-height: 1.6;
        }

        .no-submissions {
            background-color: var(--light-color);
            padding: 25px;
            border-radius: var(--border-radius);
            text-align: center;
            color: #7f8c8d;
            font-style: italic;
            margin-top: 15px;
        }

        .no-submissions i {
            font-size: 24px;
            margin-right: 10px;
            color: var(--info-color);
        }

        .admin-actions {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 30px;
        }

        .action-instructions {
            background-color: #e8f4fd;
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            color: var(--info-color);
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            margin-bottom: 10px;
            color: var(--dark-color);
        }

        .admin-notes {
            width: 100%;
            padding: 15px;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            resize: vertical;
            min-height: 120px;
            font-family: 'Poppins', sans-serif;
            font-size: 15px;
            transition: var(--transition);
        }

        .admin-notes:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px 24px;
            border-radius: var(--border-radius);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            min-width: 180px;
        }

        .btn-approve {
            background-color: var(--success-color);
            color: white;
        }

        .btn-approve:hover {
            background-color: #219653;
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(39, 174, 96, 0.3);
        }

        .btn-resubmit {
            background-color: var(--warning-color);
            color: white;
        }

        .btn-resubmit:hover {
            background-color: #e0a800;
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(243, 156, 18, 0.3);
        }

        .btn-warning {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-warning:hover {
            background-color: #c0392b;
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(231, 76, 60, 0.3);
        }

        @media (max-width: 992px) {
            .task-review-container {
                margin: 20px;
            }
            
            .task-header {
                padding: 25px;
            }
            
            .task-title {
                font-size: 24px;
            }
        }

        @media (max-width: 768px) {
            .task-meta {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
            
            .submission-meta {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .submission-status {
                align-self: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="task-review-container">
        <a href="manage_tasks.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Task Management
        </a>
        
        <div class="task-card">
            <div class="task-header">
                <h1 class="task-title"><?php echo htmlspecialchars($task['title']); ?></h1>
                <div class="task-status-container">
                    <div class="task-status status-<?php echo $task['status']; ?>">
                        <i class="fas fa-<?php echo $task['status'] === 'completed' ? 'check-circle' : ($is_overdue ? 'exclamation-circle' : 'clock'); ?>"></i>
                        <?php echo ucfirst($task['status']); ?>
                        <?php if ($is_overdue && $task['status'] === 'pending') echo " (Overdue)"; ?>
                    </div>
                </div>
            </div>
            
            <div class="task-meta">
                <div class="meta-item">
                    <div class="meta-label"><i class="fas fa-user"></i> Assigned To</div>
                    <div class="meta-value"><?php echo htmlspecialchars($task['assigned_first_name'] . ' ' . $task['assigned_last_name']); ?></div>
                </div>
                
                <div class="meta-item">
                    <div class="meta-label"><i class="fas fa-user-edit"></i> Created By</div>
                    <div class="meta-value"><?php echo htmlspecialchars($task['creator_first_name'] . ' ' . $task['creator_last_name']); ?></div>
                </div>
                
                <div class="meta-item">
                    <div class="meta-label"><i class="fas fa-calendar-alt"></i> Due Date</div>
                    <div class="meta-value <?php echo $is_overdue ? 'text-danger' : ''; ?>">
                        <?php echo $due_date; ?>
                        <?php if ($is_overdue) echo '<i class="fas fa-exclamation-circle"></i>'; ?>
                    </div>
                </div>
                
                <div class="meta-item">
                    <div class="meta-label"><i class="fas fa-flag"></i> Priority</div>
                    <div class="meta-value">
                        <?php 
                        $priorityIcon = '';
                        $priorityClass = '';
                        switch($task['priority']) {
                            case 'high':
                                $priorityIcon = '<i class="fas fa-arrow-up"></i> ';
                                $priorityClass = 'text-danger';
                                break;
                            case 'medium':
                                $priorityIcon = '<i class="fas fa-equals"></i> ';
                                $priorityClass = 'text-warning';
                                break;
                            case 'low':
                                $priorityIcon = '<i class="fas fa-arrow-down"></i> ';
                                $priorityClass = 'text-success';
                                break;
                        }
                        echo '<span class="' . $priorityClass . '">' . $priorityIcon . ucfirst($task['priority']) . '</span>';
                        ?>
                    </div>
                </div>
                
                <div class="meta-item">
                    <div class="meta-label"><i class="fas fa-clock"></i> Created At</div>
                    <div class="meta-value"><?php echo date('M j, Y g:i A', strtotime($task['created_at'])); ?></div>
                </div>
                
                <?php if ($task['status'] === 'completed' && $task['completed_at']): ?>
                <div class="meta-item">
                    <div class="meta-label"><i class="fas fa-check-circle"></i> Completed At</div>
                    <div class="meta-value"><?php echo date('M j, Y g:i A', strtotime($task['completed_at'])); ?></div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="section-card">
                <h3 class="section-header"><i class="fas fa-tasks"></i> Task Description</h3>
                <div class="task-description">
                    <?php echo nl2br(htmlspecialchars($task['description'])); ?>
                </div>
            </div>
            
            <?php if ($has_attachments): ?>
            <div class="section-card">
                <h3 class="section-header"><i class="fas fa-paperclip"></i> Task Attachments</h3>
                <div class="attachments-list">
                    <?php for ($i = 0; $i < count($attachment_names); $i++): ?>
                    <div class="attachment-item">
                        <i class="fas fa-file-alt"></i>
                        <a href="uploads/<?php echo htmlspecialchars($attachment_paths[$i]); ?>" target="_blank">
                            <?php echo htmlspecialchars($attachment_names[$i]); ?>
                        </a>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="section-card">
                <h3 class="section-header"><i class="fas fa-clipboard-check"></i> Submissions</h3>
                
                <?php if ($submissions_result->num_rows > 0): ?>
                    <div class="history-list">
                        <?php while ($submission = $submissions_result->fetch_assoc()): ?>
                        <div class="history-item">
                            <div class="submission-meta">
                                <div class="submission-by">
                                    <i class="fas fa-user-circle"></i>
                                    Submitted by <?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?> 
                                    on <?php echo date('M j, Y g:i A', strtotime($submission['submitted_at'])); ?>
                                </div>
                                <div class="submission-status status-<?php echo $submission['status']; ?>">
                                    <?php 
                                    $statusIcon = '';
                                    switch($submission['status']) {
                                        case 'pending':
                                            $statusIcon = '<i class="fas fa-clock"></i> ';
                                            break;
                                        case 'approved':
                                            $statusIcon = '<i class="fas fa-check-circle"></i> ';
                                            break;
                                        case 'rejected':
                                            $statusIcon = '<i class="fas fa-times-circle"></i> ';
                                            break;
                                    }
                                    echo $statusIcon . ucfirst($submission['status']); 
                                    ?>
                                </div>
                            </div>
                            
                            <div class="submission-content">
                                <?php echo nl2br(htmlspecialchars($submission['submission_text'])); ?>
                            </div>
                            
                            <?php if ($submission['reviewed_at']): ?>
                            <div class="review-notes">
                                <div class="review-notes-label">Review Notes:</div>
                                <div class="review-notes-content"><?php echo nl2br(htmlspecialchars($submission['review_notes'])); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="no-submissions">
                        <i class="fas fa-info-circle"></i> No submissions found for this task.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="admin-actions">
            <h3 class="section-header"><i class="fas fa-cogs"></i> Review Actions</h3>
            <p class="action-instructions">
                <i class="fas fa-info-circle"></i> Review the task submission and take appropriate action below
            </p>
            
            <form method="POST">
                <div class="form-group">
                    <label for="admin_notes"><i class="fas fa-pen"></i> Admin Notes</label>
                    <textarea name="admin_notes" id="admin_notes" class="admin-notes" placeholder="Enter your feedback or notes about this task..."></textarea>
                </div>
                
                <div class="action-buttons">
                    <?php if ($task['status'] === 'completed'): ?>
                    <button type="submit" name="approve_task" class="btn btn-approve">
                        <i class="fas fa-check-circle"></i> APPROVE TASK
                    </button>
                    
                    <button type="submit" name="request_resubmission" class="btn btn-resubmit">
                        <i class="fas fa-redo-alt"></i> REQUEST RESUBMISSION
                    </button>
                    
                    <button type="submit" name="reopen_task" class="btn btn-warning">
                        <i class="fas fa-undo-alt"></i> REOPEN TASK
                    </button>
                    <?php elseif ($task['status'] === 'pending' || $task['status'] === 'overdue'): ?>
                    <button type="submit" name="request_resubmission" class="btn btn-resubmit">
                        <i class="fas fa-redo-alt"></i> REQUEST UPDATE
                    </button>
                    
                    <button type="submit" name="reopen_task" class="btn btn-warning">
                        <i class="fas fa-undo-alt"></i> REOPEN TASK
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
