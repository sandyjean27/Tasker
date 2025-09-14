<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Database connection
$host = "localhost";
$dbname = "accountant";
$db_username = "root";
$db_password = "";

$conn = new mysqli($host, $db_username, $db_password, $dbname);

if ($conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $task_id = (int)$_POST['task_id'];
    $user_id = $_SESSION['user_id'];
    $comments = isset($_POST['comments']) ? trim($_POST['comments']) : '';

    // Validate task_id and user permissions
    $task_check = $conn->prepare("SELECT t.*, u.role FROM tasks t JOIN users u ON t.assigned_to = u.id WHERE t.id = ? AND t.assigned_to = ?");
    if (!$task_check || !$task_check->bind_param("ii", $task_id, $user_id) || !$task_check->execute()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid task or permission denied']);
        exit();
    }
    
    $task_result = $task_check->get_result();
    if ($task_result->num_rows === 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Task not found or permission denied']);
        exit();
    }

    $task_data = $task_result->fetch_assoc();
    if ($task_data['status'] === 'completed') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'This task has already been completed']);
        exit();
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        // Insert task submission
        $insert_submission = $conn->prepare("
            INSERT INTO task_submissions (task_id, submitted_by, submission_text, status, submitted_at)
            VALUES (?, ?, ?, 'pending', NOW())
        ");
        
        if (!$insert_submission) {
            throw new Exception("Error preparing submission insert: " . $conn->error);
        }

        $insert_submission->bind_param("iis", $task_id, $user_id, $comments);
        if (!$insert_submission->execute()) {
            throw new Exception("Error inserting submission: " . $insert_submission->error);
        }
        
        $submission_id = $conn->insert_id;

        // Update task status to completed
        $update_task = $conn->prepare("
            UPDATE tasks 
            SET status = 'completed', 
                completion_notes = ?, 
                updated_at = NOW(),
                completed_at = NOW()
            WHERE id = ? AND assigned_to = ?
        ");
        
        if (!$update_task) {
            throw new Exception("Error preparing task update: " . $conn->error);
        }

        $update_task->bind_param("sii", $comments, $task_id, $user_id);
        
        if (!$update_task->execute()) {
            throw new Exception("Error updating task: " . $update_task->error);
        }

        // Handle file attachments
        if (!empty($_FILES['attachments']['name'][0])) {
            $upload_dir = __DIR__ . "/uploads/task_submissions/";
            if (!file_exists($upload_dir)) {
                if (!mkdir($upload_dir, 0777, true)) {
                    throw new Exception("Failed to create upload directory");
                }
            }

            // Define allowed file types and max size (5MB)
            $allowed_types = ['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            $max_size = 5 * 1024 * 1024; // 5MB in bytes

            foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                // Validate file type
                $file_type = mime_content_type($tmp_name);
                if (!in_array($file_type, $allowed_types)) {
                    throw new Exception("Invalid file type: " . htmlspecialchars($_FILES['attachments']['name'][$key]));
                }

                // Validate file size
                if ($_FILES['attachments']['size'][$key] > $max_size) {
                    throw new Exception("File too large: " . htmlspecialchars($_FILES['attachments']['name'][$key]));
                }

                // Generate safe filename
                $file_ext = pathinfo($_FILES['attachments']['name'][$key], PATHINFO_EXTENSION);
                $safe_filename = uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $file_ext;
                $filepath = $upload_dir . $safe_filename;

                if (!move_uploaded_file($tmp_name, $filepath)) {
                    throw new Exception("Failed to upload file: " . htmlspecialchars($_FILES['attachments']['name'][$key]));
                }

                $insert_attachment = $conn->prepare("
                    INSERT INTO submission_attachments (submission_id, file_name, file_path, uploaded_by)
                    VALUES (?, ?, ?, ?)
                ");
                
                if (!$insert_attachment) {
                    throw new Exception("Error preparing attachment insert: " . $conn->error);
                }

                $original_filename = $conn->real_escape_string($_FILES['attachments']['name'][$key]);
                if (!$insert_attachment->bind_param("issi", $submission_id, $original_filename, $safe_filename, $user_id)) {
                    throw new Exception("Error binding attachment parameters: " . $insert_attachment->error);
                }

                if (!$insert_attachment->execute()) {
                    throw new Exception("Error inserting attachment: " . $insert_attachment->error);
                }
                $insert_attachment->close();
            }
        }

        // Create notification for task creator
        $notify_creator = $conn->prepare("
            INSERT INTO notifications (user_id, task_id, type, message, created_at) 
            SELECT created_by, ?, 'task_completed', 
            CONCAT('Task \"', title, '\" has been completed by ', 
                  (SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE id = ?)), 
            NOW()
            FROM tasks WHERE id = ?
        ");
        
        if (!$notify_creator || !$notify_creator->bind_param("iii", $task_id, $user_id, $task_id) || !$notify_creator->execute()) {
            throw new Exception("Error creating notification");
        }

        $conn->commit();
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Task completed successfully!']);
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error in submit_task.php: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}

$conn->close();
?>