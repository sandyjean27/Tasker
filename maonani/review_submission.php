<?php
session_start();
require_once 'config.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$conn = new mysqli($host, $db_username, $db_password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submission_id'])) {
    $submission_id = $_POST['submission_id'];
    $status = $_POST['status'];
    $review_notes = $_POST['review_notes'];
    
    // Get submission details for notification
    $submission_query = "SELECT ts.*, t.title as task_title, t.id as task_id, 
                               u.first_name, u.last_name, u.id as staff_id
                        FROM task_submissions ts
                        JOIN tasks t ON ts.task_id = t.id
                        JOIN users u ON ts.submitted_by = u.id
                        WHERE ts.id = ?";
    $sub_stmt = $conn->prepare($submission_query);
    $sub_stmt->bind_param("i", $submission_id);
    $sub_stmt->execute();
    $submission_data = $sub_stmt->get_result()->fetch_assoc();
    $sub_stmt->close();
    
    // Update submission status
    $update_query = "UPDATE task_submissions 
                    SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ?
                    WHERE id = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("siis", $status, $_SESSION['user_id'], $review_notes, $submission_id);
    $stmt->execute();
    
    // Update task status if approved
    if ($status === 'approved') {
        $task_query = "UPDATE tasks t 
                      JOIN task_submissions ts ON t.id = ts.task_id 
                      SET t.status = 'completed' 
                      WHERE ts.id = ?";
        $task_stmt = $conn->prepare($task_query);
        $task_stmt->bind_param("i", $submission_id);
        $task_stmt->execute();
        
        // Create notification for staff
        $notification_message = "Your submission for task '{$submission_data['task_title']}' has been approved.";
        $notification_query = "INSERT INTO notifications (user_id, type, task_id, submission_id, message) 
                             VALUES (?, 'task_approved', ?, ?, ?)";
        $notif_stmt = $conn->prepare($notification_query);
        $notif_stmt->bind_param("iiis", $submission_data['staff_id'], $submission_data['task_id'], $submission_id, $notification_message);
        $notif_stmt->execute();
    } else {
        // Create notification for staff about rejection
        $notification_message = "Your submission for task '{$submission_data['task_title']}' has been rejected. Please review the feedback and resubmit.";
        $notification_query = "INSERT INTO notifications (user_id, type, task_id, submission_id, message) 
                             VALUES (?, 'task_rejected', ?, ?, ?)";
        $notif_stmt = $conn->prepare($notification_query);
        $notif_stmt->bind_param("iiis", $submission_data['staff_id'], $submission_data['task_id'], $submission_id, $notification_message);
        $notif_stmt->execute();
        
        // Update task status back to in_progress
        $task_query = "UPDATE tasks t 
                      JOIN task_submissions ts ON t.id = ts.task_id 
                      SET t.status = 'in_progress' 
                      WHERE ts.id = ?";
        $task_stmt = $conn->prepare($task_query);
        $task_stmt->bind_param("i", $submission_id);
        $task_stmt->execute();
    }
    
    // Redirect back to dashboard
    header("Location: dashboard.php");
    exit();
}

// Get submission details
if (isset($_GET['id'])) {
    $submission_id = $_GET['id'];
    $query = "SELECT ts.*, t.title as task_title, t.description as task_description,
                     u.first_name, u.last_name, u.username
              FROM task_submissions ts
              JOIN tasks t ON ts.task_id = t.id
              JOIN users u ON ts.submitted_by = u.id
              WHERE ts.id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $submission_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $submission = $result->fetch_assoc();
    
    // Get attachments
    $attachments_query = "SELECT * FROM submission_attachments WHERE submission_id = ?";
    $att_stmt = $conn->prepare($attachments_query);
    $att_stmt->bind_param("i", $submission_id);
    $att_stmt->execute();
    $attachments = $att_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Task Submission</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .task-info {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        .submission-content {
            margin: 20px 0;
            padding: 15px;
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 4px;
        }
        .attachments {
            margin: 20px 0;
        }
        .attachment-item {
            display: flex;
            align-items: center;
            padding: 10px;
            background: #f8f9fa;
            margin-bottom: 5px;
            border-radius: 4px;
        }
        .review-form {
            margin-top: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            min-height: 100px;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
        .btn-approve {
            background: #28a745;
            color: white;
        }
        .btn-reject {
            background: #dc3545;
            color: white;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 14px;
            font-weight: bold;
        }
        .status-pending {
            background: #ffc107;
            color: #000;
        }
        .status-approved {
            background: #28a745;
            color: white;
        }
        .status-rejected {
            background: #dc3545;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Review Task Submission</h2>
        
        <div class="task-info">
            <h3><?php echo htmlspecialchars($submission['task_title']); ?></h3>
            <p><strong>Submitted by:</strong> <?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?></p>
            <p><strong>Submitted on:</strong> <?php echo date('F j, Y g:i A', strtotime($submission['submitted_at'])); ?></p>
            <span class="status-badge status-<?php echo $submission['status']; ?>">
                <?php echo ucfirst($submission['status']); ?>
            </span>
        </div>

        <div class="submission-content">
            <h4>Submission Details</h4>
            <p><?php echo nl2br(htmlspecialchars($submission['submission_text'])); ?></p>
        </div>

        <?php if (!empty($attachments)): ?>
        <div class="attachments">
            <h4>Attachments</h4>
            <?php foreach ($attachments as $attachment): ?>
            <div class="attachment-item">
                <i class="fas fa-paperclip"></i>
                <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>" target="_blank">
                    <?php echo htmlspecialchars($attachment['file_name']); ?>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($submission['status'] === 'pending'): ?>
        <form class="review-form" method="POST">
            <input type="hidden" name="submission_id" value="<?php echo $submission_id; ?>">
            
            <div class="form-group">
                <label for="review_notes">Review Notes:</label>
                <textarea name="review_notes" id="review_notes" required></textarea>
            </div>

            <div class="form-group">
                <button type="submit" name="status" value="approved" class="btn btn-approve">
                    <i class="fas fa-check"></i> Approve Submission
                </button>
                <button type="submit" name="status" value="rejected" class="btn btn-reject">
                    <i class="fas fa-times"></i> Reject Submission
                </button>
            </div>
        </form>
        <?php else: ?>
        <div class="review-info">
            <h4>Review Information</h4>
            <p><strong>Status:</strong> <?php echo ucfirst($submission['status']); ?></p>
            <p><strong>Reviewed on:</strong> <?php echo date('F j, Y g:i A', strtotime($submission['reviewed_at'])); ?></p>
            <p><strong>Review Notes:</strong></p>
            <p><?php echo nl2br(htmlspecialchars($submission['review_notes'])); ?></p>
        </div>
        <?php endif; ?>
    </div>
</body>
</html> 