<?php
session_start();
require_once 'db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Check if task ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: staff_tasks.php");
    exit();
}

$task_id = (int)$_GET['id'];

// Get task details
$task_query = "SELECT t.*, 
              CONCAT(u1.first_name, ' ', u1.last_name) as creator_name,
              CONCAT(u2.first_name, ' ', u2.last_name) as assigned_name
              FROM tasks t
              LEFT JOIN users u1 ON t.created_by = u1.id
              LEFT JOIN users u2 ON t.assigned_to = u2.id
              WHERE t.id = ?";

$task_stmt = $conn->prepare($task_query);
$task_stmt->bind_param("i", $task_id);
$task_stmt->execute();
$task_result = $task_stmt->get_result();

if ($task_result->num_rows === 0) {
    header("Location: staff_tasks.php");
    exit();
}

$task = $task_result->fetch_assoc();

// Check if user has access to this task
if ($role !== 'admin' && $role !== 'supervisor' && $task['assigned_to'] != $user_id && $task['created_by'] != $user_id) {
    header("Location: staff_tasks.php");
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
    $attachments[] = $attachment;
}

// Get task submissions
$submissions_query = "SELECT ts.*, 
                     CONCAT(u1.first_name, ' ', u1.last_name) as submitter_name,
                     CONCAT(u2.first_name, ' ', u2.last_name) as reviewer_name
                     FROM task_submissions ts
                     LEFT JOIN users u1 ON ts.submitted_by = u1.id
                     LEFT JOIN users u2 ON ts.reviewed_by = u2.id
                     WHERE ts.task_id = ?
                     ORDER BY ts.submitted_at DESC";

$submissions_stmt = $conn->prepare($submissions_query);
$submissions_stmt->bind_param("i", $task_id);
$submissions_stmt->execute();
$submissions_result = $submissions_stmt->get_result();

$submissions = [];
while ($submission = $submissions_result->fetch_assoc()) {
    $submissions[] = $submission;
}

// Get submission attachments
$submission_attachments = [];
if (!empty($submissions)) {
    foreach ($submissions as &$submission) {
        $sub_id = $submission['id'];
        $attach_query = "SELECT * FROM submission_attachments WHERE submission_id = ?";
        $attach_stmt = $conn->prepare($attach_query);
        $attach_stmt->bind_param("i", $sub_id);
        $attach_stmt->execute();
        $attach_result = $attach_stmt->get_result();
        
        $submission['attachments'] = [];
        while ($attachment = $attach_result->fetch_assoc()) {
            $submission['attachments'][] = $attachment;
        }
    }
}

// Handle task submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_task'])) {
    $submission_text = trim($_POST['submission_text']);
    
    if (empty($submission_text)) {
        $error_message = "Please enter submission details.";
    } else {
        $insert_query = "INSERT INTO task_submissions (task_id, submitted_by, submission_text, status) 
                        VALUES (?, ?, ?, 'pending')";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("iis", $task_id, $user_id, $submission_text);
        
        if ($insert_stmt->execute()) {
            $submission_id = $conn->insert_id;
            
            // Handle file uploads if any
            if (!empty($_FILES['attachment']['name'][0])) {
                $upload_dir = 'uploads/submissions/';
                
                // Create directory if it doesn't exist
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_count = count($_FILES['attachment']['name']);
                
                for ($i = 0; $i < $file_count; $i++) {
                    if ($_FILES['attachment']['error'][$i] === UPLOAD_ERR_OK) {
                        $tmp_name = $_FILES['attachment']['tmp_name'][$i];
                        $original_name = $_FILES['attachment']['name'][$i];
                        $file_ext = pathinfo($original_name, PATHINFO_EXTENSION);
                        $file_path = uniqid('submission_') . '_' . time() . '.' . $file_ext;
                        
                        if (move_uploaded_file($tmp_name, $upload_dir . $file_path)) {
                            // Save attachment to database
                            $attach_query = "INSERT INTO submission_attachments (submission_id, file_name, file_path) 
                                           VALUES (?, ?, ?)";
                            $attach_stmt = $conn->prepare($attach_query);
                            $attach_stmt->bind_param("iss", $submission_id, $original_name, $file_path);
                            $attach_stmt->execute();
                        }
                    }
                }
            }
            
            // Update task status if needed
            if ($task['status'] !== 'completed') {
                $update_task = "UPDATE tasks SET status = 'completed' WHERE id = ?";
                $update_stmt = $conn->prepare($update_task);
                $update_stmt->bind_param("i", $task_id);
                $update_stmt->execute();
                
                // Create notification for task creator
                require_once 'task_notifications.php';
                $message = "Task '{$task['title']}' has been completed by " . $_SESSION['first_name'] . " " . $_SESSION['last_name'];
                notifyTaskSubmission($task_id, $user_id);
            }
            
            // Redirect to refresh the page
            header("Location: view_task.php?id=$task_id&submitted=1");
            exit();
        } else {
            $error_message = "Error submitting task: " . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Task - <?php echo htmlspecialchars($task['title']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4a90e2;
            --secondary-color: #2c3e50;
            --success-color: #2ecc71;
            --warning-color: #f1c40f;
            --danger-color: #e74c3c;
            --light-color: #f5f5f5;
            --dark-color: #333;
            --border-color: #ddd;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: var(--dark-color);
            background-color: #f0f2f5;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .back-button {
            display: inline-block;
            margin-bottom: 20px;
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
        }
        
        .back-button:hover {
            text-decoration: underline;
        }
        
        .task-container {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .task-header {
            background-color: var(--primary-color);
            color: white;
            padding: 20px;
            position: relative;
        }
        
        .task-title {
            font-size: 1.5rem;
            margin-bottom: 10px;
        }
        
        .task-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            font-size: 0.9rem;
        }
        
        .task-status {
            position: absolute;
            top: 20px;
            right: 20px;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .status-pending {
            background-color: var(--warning-color);
            color: #000;
        }
        
        .status-completed {
            background-color: var(--success-color);
            color: white;
        }
        
        .status-overdue {
            background-color: var(--danger-color);
            color: white;
        }
        
        .task-content {
            padding: 20px;
        }
        
        .task-section {
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 1.2rem;
            margin-bottom: 15px;
            padding-bottom: 5px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .task-description {
            white-space: pre-line;
            line-height: 1.8;
        }
        
        .attachments-list {
            list-style: none;
        }
        
        .attachment-item {
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
        }
        
        .attachment-item i {
            margin-right: 10px;
            color: var(--primary-color);
        }
        
        .attachment-item a {
            color: var(--primary-color);
            text-decoration: none;
        }
        
        .attachment-item a:hover {
            text-decoration: underline;
        }
        
        .submission-item {
            background-color: var(--light-color);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .submission-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 0.9rem;
        }
        
        .submission-content {
            white-space: pre-line;
            margin-bottom: 15px;
            padding: 10px;
            background-color: white;
            border-radius: 4px;
            border: 1px solid var(--border-color);
        }
        
        .review-notes {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            border-left: 3px solid var(--primary-color);
        }
        
        .submission-form {
            margin-top: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            resize: vertical;
            min-height: 120px;
        }
        
        .btn {
            display: inline-block;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            transition: background-color 0.3s;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #3a7bc8;
        }
        
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="staff_tasks.php" class="back-button">
            <i class="fas fa-arrow-left"></i> Back to Tasks
        </a>
        
        <?php if (isset($_GET['submitted']) && $_GET['submitted'] == 1): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> Task submitted successfully!
        </div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
        </div>
        <?php endif; ?>
        
        <div class="task-container">
            <div class="task-header">
                <h1 class="task-title"><?php echo htmlspecialchars($task['title']); ?></h1>
                <div class="task-meta">
                    <span><i class="fas fa-calendar"></i> Due: <?php echo date('M j, Y', strtotime($task['due_date'])); ?></span>
                    <span><i class="fas fa-user"></i> From: <?php echo htmlspecialchars($task['creator_name']); ?></span>
                    <span><i class="fas fa-user-check"></i> Assigned to: <?php echo htmlspecialchars($task['assigned_name']); ?></span>
                    <?php if ($task['status'] === 'completed'): ?>
                    <span><i class="fas fa-calendar-check"></i> Completed: <?php echo date('M j, Y', strtotime($task['completed_at'])); ?></span>
                    <?php endif; ?>
                </div>
                <span class="task-status <?php 
                    if ($task['status'] === 'completed') {
                        echo 'status-completed';
                    } elseif (strtotime($task['due_date']) < time()) {
                        echo 'status-overdue';
                    } else {
                        echo 'status-pending';
                    }
                ?>">
                    <?php 
                    if ($task['status'] === 'completed') {
                        echo 'Completed';
                    } elseif (strtotime($task['due_date']) < time()) {
                        echo 'Overdue';
                    } else {
                        echo 'Pending';
                    }
                    ?>
                </span>
            </div>
            
            <div class="task-content">
                <div class="task-section">
                    <h2 class="section-title">Description</h2>
                    <div class="task-description">
                        <?php echo nl2br(htmlspecialchars($task['description'])); ?>
                    </div>
                </div>
                
                <?php if (!empty($attachments)): ?>
                <div class="task-section">
                    <h2 class="section-title">Attachments</h2>
                    <ul class="attachments-list">
                        <?php foreach ($attachments as $attachment): ?>
                        <li class="attachment-item">
                            <i class="fas fa-paperclip"></i>
                            <a href="uploads/<?php echo htmlspecialchars($attachment['file_path']); ?>" target="_blank">
                                <?php echo htmlspecialchars($attachment['file_name']); ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($submissions)): ?>
                <div class="task-section">
                    <h2 class="section-title">Submission History</h2>
                    <?php foreach ($submissions as $submission): ?>
                    <div class="submission-item">
                        <div class="submission-header">
                            <span>
                                <i class="fas fa-clock"></i> 
                                Submitted on <?php echo date('M j, Y g:i A', strtotime($submission['submitted_at'])); ?>
                                by <?php echo htmlspecialchars($submission['submitter_name']); ?>
                            </span>
                            <span class="task-status <?php 
                                if ($submission['status'] === 'approved') {
                                    echo 'status-completed';
                                } elseif ($submission['status'] === 'rejected') {
                                    echo 'status-overdue';
                                } else {
                                    echo 'status-pending';
                                }
                            ?>">
                                <?php 
                                if ($submission['status'] === 'approved') {
                                    echo 'Approved';
                                } elseif ($submission['status'] === 'rejected') {
                                    echo 'Changes Requested';
                                } else {
                                    echo 'Pending Review';
                                }
                                ?>
                            </span>
                        </div>
                        <div class="submission-content">
                            <?php echo nl2br(htmlspecialchars($submission['submission_text'])); ?>
                        </div>
                        <?php if (!empty($submission['attachments'])): ?>
                        <div class="attachments-list">
                            <?php foreach ($submission['attachments'] as $attachment): ?>
                            <li class="attachment-item">
                                <i class="fas fa-paperclip"></i>
                                <a href="uploads/submissions/<?php echo htmlspecialchars($attachment['file_path']); ?>" target="_blank">
                                    <?php echo htmlspecialchars($attachment['file_name']); ?>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($submission['review_notes'])): ?>
                        <div class="review-notes">
                            <strong>Admin Notes:</strong>
                            <p><?php echo nl2br(htmlspecialchars($submission['review_notes'])); ?></p>
                            <?php if ($submission['reviewed_at']): ?>
                            <small>Reviewed on <?php echo date('M j, Y g:i A', strtotime($submission['reviewed_at'])); ?> by <?php echo htmlspecialchars($submission['reviewer_name']); ?></small>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($task['status'] !== 'completed' && ($task['assigned_to'] == $user_id || $role === 'admin')): ?>
                <div class="task-section submission-form">
                    <h2 class="section-title">Submit Task</h2>
                    <form method="post" action="" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="submission_text">Comments/Notes</label>
                            <textarea id="submission_text" name="submission_text" rows="6" placeholder="Add your comments or notes about this task..." required></textarea>
                        </div>
                        <div class="form-group">
                            <label for="attachment">Attachments</label>
                            <input type="file" id="attachment" name="attachment[]" multiple>
                        </div>
                        <div class="form-group">
                            <button type="submit" name="submit_task" class="btn btn-primary">
                                <?php echo !empty($submissions) ? 'Resubmit Task' : 'Submit Task'; ?>
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
