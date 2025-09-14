<?php
session_start();
$host = "localhost"; 
$dbname = "accountant";
$username = "root"; 
$password = ""; 

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create message_attachments table if it doesn't exist
$create_attachments_table = "CREATE TABLE IF NOT EXISTS message_attachments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    message_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT NOT NULL,
    file_type VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
)";
$conn->query($create_attachments_table);

// Create uploads directory if it doesn't exist
$upload_dir = 'uploads/messages';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Get current tab
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'inbox';

// Handle message sending with attachments
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST['send_message'])) {
        $subject = $conn->real_escape_string($_POST['subject']);
        $message = $conn->real_escape_string($_POST['message']);
        $parent_id = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        $message_type = isset($_POST['message_type']) ? $conn->real_escape_string($_POST['message_type']) : 'casual';
        $is_group = isset($_POST['is_group']) && $_POST['is_group'] === '1';
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Insert the message
            $stmt = $conn->prepare("INSERT INTO messages (subject, message, sender_id, receiver_id, parent_id, message_type, is_group) VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            if ($role === 'admin' && $is_group) {
                // For group messages, set receiver_id to sender (used for sent items)
                $stmt->bind_param("ssiiisi", $subject, $message, $user_id, $user_id, $parent_id, $message_type, $is_group);
            } else {
                $receiver_id = (int)$_POST['receiver_id'];
                $stmt->bind_param("ssiiisi", $subject, $message, $user_id, $receiver_id, $parent_id, $message_type, $is_group);
            }
            
            $stmt->execute();
            $message_id = $conn->insert_id;
            $stmt->close();
            
            // Handle file uploads
            if (!empty($_FILES['attachments']['name'][0])) {
                $attachment_stmt = $conn->prepare("INSERT INTO message_attachments (message_id, file_name, file_path, file_size, file_type) VALUES (?, ?, ?, ?, ?)");
                
                foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['attachments']['error'][$key] === 0) {
                        $file_name = $_FILES['attachments']['name'][$key];
                        $file_type = $_FILES['attachments']['type'][$key];
                        $file_size = $_FILES['attachments']['size'][$key];
                        
                        // Generate unique filename
                        $unique_name = uniqid() . '_' . $file_name;
                        $file_path = $upload_dir . '/' . $unique_name;
                        
                        // Move uploaded file
                        if (move_uploaded_file($tmp_name, $file_path)) {
                            $attachment_stmt->bind_param("issis", $message_id, $file_name, $file_path, $file_size, $file_type);
                            $attachment_stmt->execute();
                        }
                    }
                }
                $attachment_stmt->close();
            }
            
            if ($role === 'admin' && $is_group && isset($_POST['recipients']) && is_array($_POST['recipients'])) {
                // Insert recipients for group message
                $recipient_stmt = $conn->prepare("INSERT INTO message_recipients (message_id, user_id) VALUES (?, ?)");
                foreach ($_POST['recipients'] as $recipient_id) {
                    $recipient_stmt->bind_param("ii", $message_id, $recipient_id);
                    $recipient_stmt->execute();
                    
                    // Create notification for each recipient
                    $notify_stmt = $conn->prepare("INSERT INTO notifications (user_id, message_id, type) VALUES (?, ?, 'new_message')");
                    $notify_stmt->bind_param("ii", $recipient_id, $message_id);
                    $notify_stmt->execute();
                    $notify_stmt->close();
                }
                $recipient_stmt->close();
            } else {
                // Create notification for single recipient
                $notify_stmt = $conn->prepare("INSERT INTO notifications (user_id, message_id, type) VALUES (?, ?, 'new_message')");
                $notify_stmt->bind_param("ii", $receiver_id, $message_id);
                $notify_stmt->execute();
                $notify_stmt->close();
            }
            
            $conn->commit();
            $_SESSION['success_message'] = "Message sent successfully.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = "Error sending message: " . $e->getMessage();
        }
        
        header("Location: inbox.php?tab=" . $current_tab);
        exit();
    } else if (isset($_POST['save_draft'])) {
        $subject = $conn->real_escape_string($_POST['subject']);
        $message = $conn->real_escape_string($_POST['message']);
        $message_type = $conn->real_escape_string($_POST['message_type']);
        
        $stmt = $conn->prepare("INSERT INTO messages (subject, message, sender_id, receiver_id, message_type, is_draft) VALUES (?, ?, ?, ?, ?, 1)");
        $stmt->bind_param("ssiis", $subject, $message, $user_id, $user_id, $message_type);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Draft saved successfully.";
        } else {
            $_SESSION['error_message'] = "Error saving draft: " . $conn->error;
        }
        $stmt->close();
        
        header("Location: inbox.php?tab=drafts");
        exit();
    } else if (isset($_POST['resolve_message'])) {
        $message_id = (int)$_POST['message_id'];
        
        // Update message status
        $resolve_stmt = $conn->prepare("UPDATE messages SET is_resolved = 1 WHERE id = ?");
        $resolve_stmt->bind_param("i", $message_id);
        
        if ($resolve_stmt->execute()) {
            // Create notification for the message sender
            $get_sender = $conn->prepare("SELECT sender_id FROM messages WHERE id = ?");
            $get_sender->bind_param("i", $message_id);
            $get_sender->execute();
            $sender_result = $get_sender->get_result();
            $sender = $sender_result->fetch_assoc();
            
            if ($sender) {
                $notify_stmt = $conn->prepare("INSERT INTO notifications (user_id, message_id, type, message) VALUES (?, ?, 'resolved', 'Your message has been marked as resolved')");
                $notify_stmt->bind_param("ii", $sender['sender_id'], $message_id);
                $notify_stmt->execute();
                $notify_stmt->close();
            }
            
            $get_sender->close();
            $_SESSION['success_message'] = "Message marked as resolved.";
        } else {
            $_SESSION['error_message'] = "Error updating message status.";
        }
        
        $resolve_stmt->close();
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit();
    }
}

// Fetch messages based on current tab
switch ($current_tab) {
    case 'sent':
        $messages_query = "SELECT DISTINCT m.*, 
                          u1.first_name as sender_first_name, u1.last_name as sender_last_name,
                          CASE 
                              WHEN m.is_group = 1 THEN 'Group Message'
                              ELSE CONCAT(u2.first_name, ' ', u2.last_name)
                          END as receiver_name,
                          u2.first_name as receiver_first_name, u2.last_name as receiver_last_name,
                          m.message_type
                          FROM messages m
                          JOIN users u1 ON m.sender_id = u1.id
                          LEFT JOIN users u2 ON m.receiver_id = u2.id
                          WHERE m.sender_id = ? AND m.is_draft = 0
                          ORDER BY m.created_at DESC";
        break;
        
    case 'drafts':
        $messages_query = "SELECT m.*, 
                          u1.first_name as sender_first_name, u1.last_name as sender_last_name,
                          m.message_type
                          FROM messages m
                          JOIN users u1 ON m.sender_id = u1.id
                          WHERE m.sender_id = ? AND m.is_draft = 1
                          ORDER BY m.created_at DESC";
        break;
        
    default: // inbox
        $messages_query = "SELECT DISTINCT m.*, 
                          u1.first_name as sender_first_name, u1.last_name as sender_last_name,
                          u2.first_name as receiver_first_name, u2.last_name as receiver_last_name,
                          m.message_type
                          FROM messages m
                          JOIN users u1 ON m.sender_id = u1.id
                          LEFT JOIN users u2 ON m.receiver_id = u2.id
                          LEFT JOIN message_recipients mr ON m.id = mr.message_id
                          WHERE (
                              (m.receiver_id = ? AND NOT m.is_group) 
                              OR (mr.user_id = ? AND m.is_group)
                          )
                          AND m.is_draft = 0
                          ORDER BY m.created_at DESC";
        break;
}

$stmt = $conn->prepare($messages_query);
if ($current_tab === 'inbox') {
    $stmt->bind_param("ii", $user_id, $user_id);
} else {
    $stmt->bind_param("i", $user_id);
}
$stmt->execute();
$messages_result = $stmt->get_result();
$stmt->close();

// Fetch all staff members for recipient selection
$staff_query = "SELECT id, first_name, last_name FROM users WHERE role = 'staff' ORDER BY last_name, first_name";
$staff_result = $conn->query($staff_query);

// Fetch message thread if viewing a specific message
$current_message = null;
if (isset($_GET['view'])) {
    $message_id = (int)$_GET['view'];
    $thread_query = "WITH RECURSIVE message_thread AS (
        -- Get the initial message and all its replies
        SELECT m.*, 1 as depth
        FROM messages m
        WHERE m.id = ? OR m.parent_id = ?
        
        UNION ALL
        
        -- Get parent messages recursively
        SELECT m.*, mt.depth + 1
        FROM messages m
        JOIN message_thread mt ON m.id = mt.parent_id
    )
    SELECT DISTINCT mt.*, 
           u1.first_name as sender_first_name, u1.last_name as sender_last_name,
           CASE 
               WHEN mt.is_group = 1 THEN 'Group Message'
               ELSE CONCAT(u2.first_name, ' ', u2.last_name)
           END as receiver_name,
           u2.first_name as receiver_first_name, u2.last_name as receiver_last_name
    FROM message_thread mt
    JOIN users u1 ON mt.sender_id = u1.id
    LEFT JOIN users u2 ON mt.receiver_id = u2.id
    ORDER BY mt.depth DESC, mt.created_at ASC";
    
    $thread_stmt = $conn->prepare($thread_query);
    $thread_stmt->bind_param("ii", $message_id, $message_id);
    $thread_stmt->execute();
    $thread_result = $thread_stmt->get_result();
    $thread_stmt->close();

    // Check if the message exists
    if ($thread_result->num_rows === 0) {
        header("Location: inbox.php");
        exit();
    }

    // Get the first message for the reply form
    $thread_result->data_seek(0);
    $current_message = $thread_result->fetch_assoc();
}

// Get unread notifications count
$notifications_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND `read` = 0";
$notify_stmt = $conn->prepare($notifications_query);
$notify_stmt->bind_param("i", $user_id);
$notify_stmt->execute();
$notify_result = $notify_stmt->get_result();
$notify_count = $notify_result->fetch_assoc()['count'];
$notify_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inbox - Task Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4a90e2;
            --primary-light: #6ba4e7;
            --primary-dark: #357abd;
            --secondary-color: #f5f6fa;
            --danger-color: #e74c3c;
            --success-color: #2ecc71;
            --warning-color: #f1c40f;
            --text-color: #2c3e50;
            --text-light: #7f8c8d;
            --border-color: #e0e0e0;
            --background-color: #f8f9fa;
            --card-background: #ffffff;
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 8px rgba(0,0,0,0.1);
            --shadow-lg: 0 8px 16px rgba(0,0,0,0.1);
            --gradient-bg: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --transition: all 0.3s ease;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            margin: 0;
            padding: 0;
            background: var(--gradient-bg);
            color: var(--text-color);
            min-height: 100vh;
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 20px auto;
            padding: 24px;
            background: var(--card-background);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .notification-badge {
            background: var(--danger-color);
            color: white;
            padding: 6px 12px;
            border-radius: var(--radius-lg);
            font-size: 14px;
            font-weight: 600;
            box-shadow: var(--shadow-sm);
        }

        .back-btn, .compose-btn {
            padding: 12px 24px;
            border-radius: var(--radius-md);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: var(--transition);
            cursor: pointer;
            border: none;
            box-shadow: var(--shadow-sm);
        }

        .back-btn {
            background: var(--secondary-color);
            color: var(--text-color);
            margin-right: 12px;
        }

        .compose-btn {
            background: var(--primary-color);
            color: white;
        }

        .back-btn:hover, .compose-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .tabs {
            display: flex;
            margin-bottom: 24px;
            background: var(--background-color);
            padding: 8px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            gap: 8px;
        }
        
        .tab {
            padding: 12px 24px;
            cursor: pointer;
            border-radius: var(--radius-md);
            transition: var(--transition);
            color: var(--text-light);
            text-decoration: none;
            font-weight: 500;
            flex: 1;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .tab.active {
            background: var(--primary-color);
            color: white;
            box-shadow: var(--shadow-sm);
        }
        
        .tab:hover:not(.active) {
            background: rgba(74, 144, 226, 0.1);
            color: var(--primary-color);
        }

        .message-list {
            background: var(--card-background);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .message-item {
            display: grid;
            grid-template-columns: auto 1fr auto auto;
            align-items: center;
            padding: 20px;
            gap: 20px;
            border-bottom: 1px solid var(--border-color);
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            padding-left: 24px;
        }

        .message-item:hover {
            background: var(--background-color);
            transform: translateX(4px);
        }

        .message-item.unread {
            background: rgba(74, 144, 226, 0.05);
            position: relative;
        }

        .message-item.unread::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: var(--primary-color);
            border-radius: 0 2px 2px 0;
        }

        .message-item.resolved {
            background: rgba(46, 204, 113, 0.05);
            border-left: 4px solid var(--success-color);
        }

        .message-item.resolved::before {
            display: none;
        }

        .message-sender {
            width: 200px;
            font-weight: 600;
            color: var(--text-color);
        }

        .message-content-wrapper {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .message-subject-line {
            font-weight: 500;
            color: var(--text-color);
        }

        .message-preview {
            color: var(--text-light);
            font-size: 0.9rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 500px;
        }

        .message-type-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            min-width: 120px;
            justify-content: center;
            box-shadow: var(--shadow-sm);
        }

        .message-type-indicator i {
            font-size: 1rem;
        }

        .type-concern {
            background: #ffebee;
            color: #c62828;
            border: 1px solid rgba(198, 40, 40, 0.2);
        }

        .type-reminder {
            background: #fff3e0;
            color: #ef6c00;
            border: 1px solid rgba(239, 108, 0, 0.2);
        }

        .type-casual {
            background: #e3f2fd;
            color: #1565c0;
            border: 1px solid rgba(21, 101, 192, 0.2);
        }

        .message-date {
            width: 150px;
            text-align: right;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .compose-form {
            background: var(--card-background);
            padding: 30px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            margin-top: 24px;
            border: 1px solid var(--border-color);
        }

        .compose-form h2 {
            margin: 0 0 24px 0;
            color: var(--text-color);
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
            font-size: 0.95rem;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 0.95rem;
            transition: var(--transition);
            background: var(--background-color);
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(74, 144, 226, 0.1);
            background: white;
        }

        .form-group textarea {
            min-height: 150px;
            resize: vertical;
        }

        .button-group {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }

        .send-btn, .cancel-btn {
            padding: 12px 24px;
            border-radius: var(--radius-md);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: var(--shadow-sm);
        }

        .send-btn {
            background: var(--primary-color);
            color: white;
        }

        .cancel-btn {
            background: var(--secondary-color);
            color: var(--text-color);
        }

        .send-btn:hover, .cancel-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .message-thread {
            background: var(--card-background);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            margin-top: 24px;
            border: 1px solid var(--border-color);
        }

        .message-thread-item {
            padding: 24px;
            border-bottom: 1px solid var(--border-color);
            position: relative;
            transition: var(--transition);
        }

        .message-thread-item:hover {
            background: var(--background-color);
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            color: var(--text-light);
            font-size: 14px;
        }

        .message-content {
            white-space: pre-wrap;
            line-height: 1.6;
            color: var(--text-color);
            padding: 16px;
            background: var(--background-color);
            border-radius: var(--radius-md);
            margin-top: 12px;
            box-shadow: var(--shadow-sm);
        }

        .reply-form {
            padding: 24px;
            background: var(--background-color);
            border-radius: 0 0 var(--radius-lg) var(--radius-lg);
        }

        .reply-form h3 {
            margin: 0 0 20px 0;
            color: var(--text-color);
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .file-input {
            border: 2px dashed var(--border-color);
            padding: 20px;
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: var(--transition);
            background: white;
        }

        .file-input:hover {
            border-color: var(--primary-color);
            background: rgba(74, 144, 226, 0.05);
        }

        .attachment-preview {
            margin-top: 16px;
            padding: 16px;
            background: var(--background-color);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
        }

        .attachment-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            background: white;
            border-radius: var(--radius-sm);
            margin-bottom: 8px;
        }

        .attachment-item:last-child {
            margin-bottom: 0;
            border-bottom: none;
        }

        .attachment-icon {
            color: var(--primary-color);
            font-size: 1.2rem;
        }

        .attachment-name {
            flex-grow: 1;
            font-weight: 500;
        }

        .attachment-size {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .attachment-download {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            transition: var(--transition);
        }

        .attachment-download:hover {
            background: rgba(74, 144, 226, 0.1);
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--background-color);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary-light);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-color);
        }

        @media (max-width: 768px) {
            .container {
                margin: 0;
                padding: 16px;
                border-radius: 0;
            }

            .header {
                flex-direction: column;
                gap: 16px;
                align-items: flex-start;
            }

            .message-item {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .message-preview {
                max-width: 100%;
            }

            .button-group {
                flex-direction: column;
            }

            .send-btn, 
            .cancel-btn {
                width: 100%;
                justify-content: center;
            }

            .message-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .tabs {
                flex-wrap: wrap;
            }

            .tab {
                flex: 1 1 calc(50% - 8px);
            }
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
            padding: 20px;
        }

        .modal-overlay.active {
            display: flex;
            opacity: 1;
        }

        .modal {
            background: var(--card-background);
            width: 95%;
            max-width: 900px;
            border-radius: var(--radius-lg);
            box-shadow: 
                0 10px 25px -5px rgba(0,0,0,0.2),
                0 5px 15px -5px rgba(0,0,0,0.1),
                0 0 0 1px rgba(255,255,255,0.1);
            position: relative;
            transform: translateY(-20px) scale(0.95);
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            margin: auto;
        }

        .modal.active {
            transform: translateY(0) scale(1);
        }

        .modal-header {
            padding: 24px 32px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(to right, var(--card-background), var(--background-color));
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.5rem;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .modal-header h2 i {
            color: var(--primary-color);
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-light);
            cursor: pointer;
            font-size: 1.25rem;
            padding: 8px;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            margin: -8px;
        }

        .modal-close:hover {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger-color);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 32px;
            overflow-y: auto;
            max-height: calc(90vh - 180px);
            scrollbar-width: thin;
            scrollbar-color: var(--primary-light) var(--background-color);
        }

        .modal-footer {
            padding: 24px 32px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: linear-gradient(to right, var(--card-background), var(--background-color));
            border-radius: 0 0 var(--radius-lg) var(--radius-lg);
        }

        .modal-footer button {
            min-width: 120px;
            justify-content: center;
        }

        body.modal-open {
            overflow: hidden;
            padding-right: 15px; /* Prevent layout shift when scrollbar disappears */
        }

        @media (max-width: 768px) {
            .modal {
                width: 100%;
                max-height: 95vh;
                margin: 10px;
            }

            .modal-header,
            .modal-body,
            .modal-footer {
                padding: 20px;
            }
        }

        /* Add these styles before the modal-overlay styles */
        .compose-modal-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .compose-modal-grid .form-group:last-child,
        .compose-modal-grid .form-group.full-width {
            grid-column: 1 / -1;
        }

        .recipient-preview {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }

        .recipient-chip {
            background: var(--primary-color);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .recipient-chip .remove-recipient {
            cursor: pointer;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transition: var(--transition);
        }

        .recipient-chip .remove-recipient:hover {
            background: rgba(255, 255, 255, 0.4);
        }

        .file-drop-zone {
            border: 2px dashed var(--border-color);
            padding: 30px;
            border-radius: var(--radius-md);
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            background: var(--background-color);
            position: relative;
        }

        .file-drop-zone:hover,
        .file-drop-zone.drag-over {
            border-color: var(--primary-color);
            background: rgba(74, 144, 226, 0.05);
        }

        .file-drop-zone i {
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        .file-drop-zone p {
            margin: 0;
            color: var(--text-light);
        }

        .file-drop-zone input[type="file"] {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .attachment-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 15px;
        }

        .attachment-chip {
            background: var(--background-color);
            padding: 8px 12px;
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: var(--shadow-sm);
        }

        .attachment-chip i {
            color: var(--primary-color);
        }

        .attachment-chip .remove-file {
            color: var(--danger-color);
            cursor: pointer;
            transition: var(--transition);
        }

        .attachment-chip .remove-file:hover {
            transform: scale(1.1);
        }

        .message-type-select {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
        }

        .message-type-option {
            flex: 1;
            padding: 16px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            opacity: 0.5;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }

        .message-type-option:hover {
            opacity: 0.8;
            transform: translateY(-2px);
        }

        .message-type-option.active {
            opacity: 1;
            border-color: var(--primary-color);
            background: rgba(74, 144, 226, 0.1);
        }

        .message-type-option i {
            font-size: 1.5rem;
            margin-bottom: 8px;
        }

        .message-type-option[data-type="casual"] {
            border-color: #1565c0;
            color: #1565c0;
        }

        .message-type-option[data-type="concern"] {
            border-color: #c62828;
            color: #c62828;
        }

        .message-type-option[data-type="reminder"] {
            border-color: #ef6c00;
            color: #ef6c00;
        }

        .message-type-option[data-type="casual"].active {
            background: rgba(21, 101, 192, 0.1);
        }

        .message-type-option[data-type="concern"].active {
            background: rgba(198, 40, 40, 0.1);
        }

        .message-type-option[data-type="reminder"].active {
            background: rgba(239, 108, 0, 0.1);
        }

        /* Add these styles for the badges */
        .badge-concern, .badge-reminder, .badge-casual {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .badge-concern {
            background-color: #ffebee;
            color: #c62828;
            border: 1px solid rgba(198, 40, 40, 0.2);
        }

        .badge-reminder {
            background-color: #fff3e0;
            color: #ef6c00;
            border: 1px solid rgba(239, 108, 0, 0.2);
        }

        .badge-casual {
            background-color: #e3f2fd;
            color: #1565c0;
            border: 1px solid rgba(21, 101, 192, 0.2);
        }

        .badge-concern i, .badge-reminder i, .badge-casual i {
            font-size: 0.875rem;
        }

        .resolve-btn {
            background: var(--success-color);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius-md);
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
        }
        
        .resolve-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            background: #27ae60;
        }
        
        .resolved-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: #e8f5e9;
            color: #2e7d32;
            border-radius: var(--radius-md);
            font-weight: 600;
            margin-top: 16px;
            border: 1px solid rgba(46, 125, 50, 0.2);
        }
        
        .message-item.resolved .message-content-wrapper {
            opacity: 0.8;
        }
        
        .message-item.resolved .badge-concern,
        .message-item.resolved .badge-reminder {
            background: #e8f5e9;
            color: #2e7d32;
            border-color: rgba(46, 125, 50, 0.2);
        }

        /* Message view modal specific styles */
        #messageViewModal .modal {
            max-width: 1200px;
        }

        #messageViewContent {
            padding: 0;
            display: flex;
            gap: 20px;
        }

        #messageViewContent .message-thread-container {
            flex: 1;
            max-height: 70vh;
            overflow-y: auto;
            padding: 20px;
            border-right: 1px solid var(--border-color);
        }

        #messageViewContent .message-thread-item {
            border: none;
            border-bottom: 1px solid var(--border-color);
            padding: 20px 0;
        }

        #messageViewContent .message-thread-item:last-child {
            border-bottom: none;
        }

        #messageViewContent .message-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        #messageViewContent .message-header > div:first-child {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        #messageViewContent .message-header strong {
            color: var(--text-color);
        }

        #messageViewContent .message-subject {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--text-color);
        }

        #messageViewContent .message-content {
            white-space: pre-wrap;
            line-height: 1.6;
            color: var(--text-color);
        }

        #messageViewContent .reply-form {
            flex: 1;
            padding: 20px;
            background: var(--background-color);
            max-height: 70vh;
            overflow-y: auto;
        }

        #messageViewContent .reply-form h3 {
            margin: 0 0 20px 0;
            color: var(--text-color);
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        #messageViewContent .reply-form .form-group {
            margin-bottom: 20px;
        }

        #messageViewContent .reply-form label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-color);
            font-weight: 500;
        }

        #messageViewContent .reply-form textarea {
            width: 100%;
            min-height: 150px;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            resize: vertical;
        }

        #messageViewContent .reply-form .button-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        @media (max-width: 768px) {
            #messageViewContent {
                flex-direction: column;
            }
            
            #messageViewContent .message-thread-container {
                border-right: none;
                border-bottom: 1px solid var(--border-color);
            }
            
            #messageViewContent .reply-form {
                max-height: none;
            }
        }

        .message-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            padding: 0 20px 20px;
        }

        .message-thread-item {
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Add these styles to your existing CSS */
        .table-controls {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            align-items: center;
        }

        .table-length select {
            margin: 0 10px;
            padding: 5px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-color);
        }

        .table-search input {
            padding: 8px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-color);
            margin-left: 10px;
            width: 200px;
        }

        .messages-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: var(--radius-md);
            overflow: hidden;
        }

        .messages-table th,
        .messages-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .messages-table th {
            background: var(--background-color);
            font-weight: 600;
            color: var(--text-color);
        }

        .messages-table tr:hover {
            background: var(--background-color);
        }

        .status-badge {
            padding: 5px 10px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .status-badge.resolved {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .status-badge.pending {
            background: #fff3e0;
            color: #ef6c00;
        }

        .action-btn {
            padding: 6px;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            margin-right: 5px;
            transition: var(--transition);
        }

        .action-btn.view {
            background: var(--primary-color);
            color: white;
        }

        .action-btn.resolve {
            background: var(--success-color);
            color: white;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .table-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding: 15px 0;
        }

        .pagination-btn {
            padding: 8px 15px;
            border: 1px solid var(--border-color);
            background: white;
            border-radius: var(--radius-sm);
            cursor: pointer;
            margin: 0 5px;
            transition: var(--transition);
        }

        .pagination-btn:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        #paginationNumbers {
            display: inline-flex;
            gap: 5px;
        }

        .page-number {
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: var(--transition);
        }

        .page-number.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .no-data {
            text-align: center;
            color: var(--text-light);
            padding: 20px !important;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Messages <?php if ($notify_count > 0): ?><span class="notification-badge"><?php echo $notify_count; ?></span><?php endif; ?></h1>
            <div>
                <a href="dashboard.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <button class="compose-btn" onclick="showComposeForm()">
                    <i class="fas fa-pen"></i> Compose
                </button>
            </div>
        </div>

        <div class="tabs">
            <a href="?tab=inbox" class="tab <?php echo $current_tab === 'inbox' ? 'active' : ''; ?>">
                <i class="fas fa-inbox"></i> Inbox
            </a>
            <a href="?tab=sent" class="tab <?php echo $current_tab === 'sent' ? 'active' : ''; ?>">
                <i class="fas fa-paper-plane"></i> Sent
            </a>
            <a href="?tab=drafts" class="tab <?php echo $current_tab === 'drafts' ? 'active' : ''; ?>">
                <i class="fas fa-save"></i> Drafts
            </a>
        </div>

        <div class="message-list">
            <div class="table-controls">
                <div class="table-length">
                    Show 
                    <select onchange="changePageSize(this.value)">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                    entries
                </div>
                <div class="table-search">
                    Search: <input type="text" onkeyup="searchMessages(this.value)">
                </div>
            </div>

            <table class="messages-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>From/To</th>
                        <th>Subject</th>
                        <th>Type</th>
                        <th>Preview</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="messageTableBody">
                    <?php if ($messages_result->num_rows > 0): ?>
                        <?php while ($message = $messages_result->fetch_assoc()): ?>
                            <tr class="<?php echo !$message['is_read'] ? 'unread' : ''; ?> <?php echo $message['is_resolved'] ? 'resolved' : ''; ?>"
                                data-message-id="<?php echo $message['id']; ?>">
                                <td><?php echo $message['id']; ?></td>
                                <td>
                                    <?php 
                                    if ($current_tab === 'sent') {
                                        echo "To: " . htmlspecialchars($message['receiver_name']);
                                    } else {
                                        echo "From: " . htmlspecialchars($message['sender_first_name'] . ' ' . $message['sender_last_name']);
                                    }
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($message['subject']); ?></td>
                                <td>
                                    <?php if (!empty($message['message_type'])): ?>
                                        <span class="badge-<?php echo strtolower(htmlspecialchars($message['message_type'])); ?>">
                                            <?php echo strtoupper(htmlspecialchars($message['message_type'])); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars(substr($message['message'], 0, 50)) . '...'; ?></td>
                                <td><?php echo date('M d, Y h:i A', strtotime($message['created_at'])); ?></td>
                                <td>
                                    <?php if ($message['is_resolved']): ?>
                                        <span class="status-badge resolved">Resolved</span>
                                    <?php else: ?>
                                        <span class="status-badge pending">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('Y-m-d H:i:s', strtotime($message['created_at'])); ?></td>
                                <td>
                                    <button class="action-btn view" onclick="viewMessage(<?php echo $message['id']; ?>, '<?php echo $current_tab; ?>')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($role === 'admin' && !$message['is_resolved']): ?>
                                        <button class="action-btn resolve" onclick="resolveMessage(<?php echo $message['id']; ?>)">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="no-data">No data available in table</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="table-footer">
                <div class="table-info">
                    Showing <span id="startEntry">0</span> to <span id="endEntry">0</span> of <span id="totalEntries">0</span> entries
                </div>
                <div class="table-pagination">
                    <button onclick="previousPage()" class="pagination-btn">Previous</button>
                    <div id="paginationNumbers"></div>
                    <button onclick="nextPage()" class="pagination-btn">Next</button>
                </div>
            </div>
        </div>

        <?php if (isset($_GET['view'])): ?>
        <div class="message-thread">
            <?php if ($current_tab === 'drafts'): ?>
            <!-- Draft Message Display -->
            <div class="message-thread-item">
                <div class="message-header">
                    <div>
                        <strong>Draft Message</strong>
                    </div>
                    <div>
                        <?php echo date('M d, Y h:i A', strtotime($current_message['created_at'])); ?>
                    </div>
                </div>
                <div class="message-subject">
                    <span class="message-type type-<?php echo htmlspecialchars($current_message['message_type']); ?>">
                        <?php echo ucfirst(htmlspecialchars($current_message['message_type'])); ?>
                    </span>
                    <strong><?php echo htmlspecialchars($current_message['subject']); ?></strong>
                </div>
                <div class="message-content">
                    <?php echo nl2br(htmlspecialchars($current_message['message'])); ?>
                </div>
                <div class="button-group" style="padding: 20px;">
                    <button type="button" class="send-btn" onclick="editDraft(<?php echo $current_message['id']; ?>)">
                        <i class="fas fa-edit"></i> Edit Draft
                    </button>
                    <button type="button" class="cancel-btn" onclick="window.location.href='inbox.php?tab=drafts'">
                        <i class="fas fa-arrow-left"></i> Back to Drafts
                    </button>
                </div>
            </div>
            <?php else: ?>
            <!-- Regular Message Thread Display -->
            <?php while ($thread_message = $thread_result->fetch_assoc()): ?>
                <div class="message-thread-item">
                    <div class="message-header">
                        <div>
                            <?php if ($current_tab === 'sent'): ?>
                                <strong>To:</strong> <?php echo htmlspecialchars($thread_message['receiver_name']); ?>
                                <strong>From:</strong> <?php echo htmlspecialchars($thread_message['sender_first_name'] . ' ' . $thread_message['sender_last_name']); ?>
                            <?php else: ?>
                            <strong>From:</strong> <?php echo htmlspecialchars($thread_message['sender_first_name'] . ' ' . $thread_message['sender_last_name']); ?>
                            <strong>To:</strong> <?php echo htmlspecialchars($thread_message['receiver_name']); ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php echo date('M d, Y h:i A', strtotime($thread_message['created_at'])); ?>
                        </div>
                    </div>
                    <div class="message-subject">
                        <?php if (!empty($thread_message['message_type'])): ?>
                            <div class="badge-<?php echo strtolower(htmlspecialchars($thread_message['message_type'])); ?>" style="margin-bottom: 10px;">
                                <?php 
                                $icon = '';
                                $label = '';
                                switch(strtolower($thread_message['message_type'])) {
                                    case 'concern':
                                        $icon = 'fa-exclamation-circle';
                                        $label = 'CONCERN';
                                        break;
                                    case 'reminder':
                                        $icon = 'fa-bell';
                                        $label = 'REMINDER';
                                        break;
                                    case 'casual':
                                        $icon = 'fa-comment';
                                        $label = 'CASUAL';
                                        break;
                                }
                                ?>
                                <i class="fas <?php echo $icon; ?>"></i>
                                <span><?php echo $label; ?></span>
                            </div>
                        <?php endif; ?>
                        <strong><?php echo htmlspecialchars($thread_message['subject']); ?></strong>
                    </div>
                    <div class="message-content">
                        <?php echo nl2br(htmlspecialchars($thread_message['message'])); ?>
                    </div>
                    
                    <?php if ($_SESSION['role'] === 'admin' && 
                              ($thread_message['message_type'] === 'concern' || $thread_message['message_type'] === 'reminder') && 
                              !$thread_message['is_resolved']): ?>
                        <div class="resolve-action" style="margin-top: 20px;">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="message_id" value="<?php echo $thread_message['id']; ?>">
                                <button type="submit" name="resolve_message" class="resolve-btn">
                                    <i class="fas fa-check-circle"></i> Mark as Resolved
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($thread_message['is_resolved']): ?>
                        <div class="resolved-badge">
                            <i class="fas fa-check-circle"></i> Resolved
                        </div>
                    <?php endif; ?>
                    
                    <?php
                    // Fetch and display attachments
                    $attachment_query = "SELECT * FROM message_attachments WHERE message_id = ?";
                    $attachment_stmt = $conn->prepare($attachment_query);
                    $attachment_stmt->bind_param("i", $thread_message['id']);
                    $attachment_stmt->execute();
                    $attachments = $attachment_stmt->get_result();
                    $attachment_stmt->close();

                    if ($attachments->num_rows > 0): ?>
                    <div class="attachment-preview">
                        <div style="margin-bottom: 10px;"><strong><i class="fas fa-paperclip"></i> Attachments:</strong></div>
                        <?php while ($attachment = $attachments->fetch_assoc()): ?>
                        <div class="attachment-item">
                            <i class="fas fa-file attachment-icon"></i>
                            <span class="attachment-name"><?php echo htmlspecialchars($attachment['file_name']); ?></span>
                            <span class="attachment-size"><?php echo number_format($attachment['file_size'] / 1024, 2); ?> KB</span>
                            <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>" 
                               class="attachment-download" 
                               download="<?php echo htmlspecialchars($attachment['file_name']); ?>">
                                <i class="fas fa-download"></i> Download
                            </a>
                        </div>
                        <?php endwhile; ?>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>

            <?php if ($current_tab === 'inbox'): ?>
            <!-- Reply Form - Only shown in inbox -->
            <div class="reply-form">
                <h3>Reply</h3>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="parent_id" value="<?php echo $current_message['id']; ?>">
                    <div class="form-group">
                        <label>Subject</label>
                        <input type="text" name="subject" value="Re: <?php echo htmlspecialchars($current_message['subject']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Message Type</label>
                        <select name="message_type" required>
                            <option value="concern" <?php echo $current_message['message_type'] === 'concern' ? 'selected' : ''; ?>>Concern</option>
                            <option value="reminder" <?php echo $current_message['message_type'] === 'reminder' ? 'selected' : ''; ?>>Reminder</option>
                            <option value="casual" <?php echo $current_message['message_type'] === 'casual' ? 'selected' : ''; ?>>Casual</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Attachments</label>
                        <input type="file" name="attachments[]" multiple class="file-input">
                        <div class="file-list"></div>
                    </div>
                    <?php if ($current_message['is_group']): ?>
                        <input type="hidden" name="is_group" value="1">
                        <input type="hidden" name="recipients[]" value="<?php echo htmlspecialchars($current_message['sender_id']); ?>">
                    <?php else: ?>
                        <input type="hidden" name="receiver_id" value="<?php echo $current_message['sender_id']; ?>">
                    <?php endif; ?>
                    <div class="form-group">
                        <label>Message</label>
                        <textarea name="message" required></textarea>
                    </div>
                    <div class="button-group">
                        <button type="submit" name="send_message" class="send-btn">Send Reply</button>
                        <button type="button" class="cancel-btn" onclick="window.location.href='inbox.php?tab=<?php echo $current_tab; ?>'">Cancel</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- Back button for all views -->
            <div class="button-group" style="padding: 20px;">
                <button type="button" class="cancel-btn" onclick="window.location.href='inbox.php?tab=<?php echo $current_tab; ?>'">
                    <i class="fas fa-arrow-left"></i> Back to <?php echo ucfirst($current_tab); ?>
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div id="composeModal" class="modal-overlay">
            <div class="modal">
                <div class="modal-header">
                    <h2><i class="fas fa-pen"></i> New Message</h2>
                    <button class="modal-close" onclick="hideComposeForm()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <form method="POST" enctype="multipart/form-data" id="composeForm">
                        <div class="message-type-select">
                            <div class="message-type-option" data-type="casual">
                                <i class="fas fa-comment"></i>
                                <span>Casual</span>
                            </div>
                            <div class="message-type-option" data-type="concern">
                                <i class="fas fa-exclamation-circle"></i>
                                <span>Concern</span>
                            </div>
                            <div class="message-type-option" data-type="reminder">
                                <i class="fas fa-bell"></i>
                                <span>Reminder</span>
                            </div>
                        </div>
                        <input type="hidden" name="message_type" id="messageType" value="">
                        
                        <div class="compose-modal-grid">
                            <div class="form-group">
                                <label>Subject</label>
                                <input type="text" name="subject" required placeholder="Enter message subject">
                            </div>
                            
                            <?php if ($role === 'admin'): ?>
                            <div class="form-group">
                                <label>Recipients</label>
                                <select onchange="toggleRecipientSelector(this.value)">
                                    <option value="single">Single Recipient</option>
                                    <option value="group">Group Message</option>
                                </select>
                            </div>
                            
                            <div id="singleRecipient" class="form-group">
                                <label>To</label>
                                <select name="receiver_id" onchange="updateRecipientPreview(this)">
                                    <option value="">Select recipient</option>
                                    <?php 
                                    $staff_result->data_seek(0);
                                    while ($staff = $staff_result->fetch_assoc()): 
                                    ?>
                                        <option value="<?php echo $staff['id']; ?>">
                                            <?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <div class="recipient-preview" id="singleRecipientPreview"></div>
                            </div>
                            
                            <div id="groupRecipients" class="recipient-selector form-group" style="display: none;">
                                <label>Select Recipients</label>
                                <input type="hidden" name="is_group" id="is_group" value="0">
                                <div class="recipient-list">
                                    <?php 
                                    $staff_result->data_seek(0);
                                    while ($staff = $staff_result->fetch_assoc()): 
                                    ?>
                                        <div class="recipient-item">
                                            <input type="checkbox" name="recipients[]" value="<?php echo $staff['id']; ?>" 
                                                   onchange="updateGroupRecipientPreview(this, '<?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?>')">
                                            <?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                                <div class="recipient-preview" id="groupRecipientPreview"></div>
                            </div>
                            <?php else: ?>
                            <!-- For staff users - always send to admin -->
                            <div class="form-group">
                                <label>To</label>
                                <input type="text" value="Admin" readonly class="form-control">
                                <?php
                                // Get admin user ID
                                $admin_query = "SELECT id FROM users WHERE role = 'admin' LIMIT 1";
                                $admin_result = $conn->query($admin_query);
                                $admin = $admin_result->fetch_assoc();
                                ?>
                                <input type="hidden" name="receiver_id" value="<?php echo $admin['id']; ?>">
                            </div>
                            <?php endif; ?>

                            <div class="form-group full-width">
                                <label>Attachments</label>
                                <div class="file-drop-zone" id="fileDropZone">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <p>Drag & drop files here or click to select</p>
                                    <input type="file" name="attachments[]" multiple onchange="handleFileSelect(this)">
                                </div>
                                <div class="attachment-list" id="attachmentList"></div>
                            </div>

                            <div class="form-group full-width">
                                <label>Message</label>
                                <textarea name="message" required placeholder="Type your message here..."></textarea>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="cancel-btn" onclick="hideComposeForm()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="button" class="send-btn" onclick="submitComposeForm('send')">
                        <i class="fas fa-paper-plane"></i> Send
                    </button>
                    <button type="button" class="cancel-btn" onclick="submitComposeForm('draft')" style="background: var(--background-color);">
                        <i class="fas fa-save"></i> Save as Draft
                    </button>
                </div>
            </div>
        </div>

        <!-- Add this modal structure before the closing </div> of the container -->
        <div id="messageViewModal" class="modal-overlay">
            <div class="modal">
                <div class="modal-header">
                    <h2><i class="fas fa-envelope-open-text"></i> Message Details</h2>
                    <button class="modal-close" onclick="hideMessageModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body" id="messageViewContent">
                    <div class="message-thread-container">
                        <!-- Message thread will be loaded here -->
                    </div>
                    <div class="reply-form">
                        <h3><i class="fas fa-reply"></i> Reply</h3>
                        <form id="replyForm" onsubmit="return submitReply(this)">
                            <input type="hidden" name="parent_id" id="replyParentId">
                            <input type="hidden" name="original_sender" id="originalSender">
                            <div class="form-group">
                                <label>Reply Message</label>
                                <textarea name="message" required placeholder="Type your reply..."></textarea>
                            </div>
                            <div class="form-group">
                                <label>Attachments (optional)</label>
                                <div class="file-drop-zone" id="replyFileDropZone">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <p>Drag & drop files here or click to select</p>
                                    <input type="file" name="attachments[]" multiple onchange="handleFileSelect(this)">
                                </div>
                                <div class="attachment-list" id="replyAttachmentList"></div>
                            </div>
                            <div class="button-group">
                                <button type="submit" class="send-btn">
                                    <i class="fas fa-paper-plane"></i> Send Reply
                                </button>
                                <button type="button" class="cancel-btn" onclick="hideMessageModal()">
                                    <i class="fas fa-times"></i> Close
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    function showComposeForm() {
        const modal = document.getElementById('composeModal');
        const modalContent = modal.querySelector('.modal');
        document.body.classList.add('modal-open');
        modal.classList.add('active');
        setTimeout(() => modalContent.classList.add('active'), 10);
        
        // Reset form when opening
        document.getElementById('composeForm').reset();
        document.getElementById('messageType').value = '';
        document.getElementById('attachmentList').innerHTML = '';
        document.getElementById('singleRecipientPreview').innerHTML = '';
        document.getElementById('groupRecipientPreview').innerHTML = '';
        
        // Reset message type options
        document.querySelectorAll('.message-type-option').forEach(opt => {
            opt.classList.remove('active');
            opt.style.opacity = '0.5';
        });
    }

    function hideComposeForm() {
        const modal = document.getElementById('composeModal');
        const modalContent = modal.querySelector('.modal');
        document.body.classList.remove('modal-open');
        modalContent.classList.remove('active');
        setTimeout(() => modal.classList.remove('active'), 300);
    }

    function submitComposeForm(type) {
        const form = document.getElementById('composeForm');
        const messageType = document.getElementById('messageType').value;
        
        // Validate message type is selected
        if (!messageType) {
            alert('Please select a message type (Casual, Concern, or Reminder)');
            return false;
        }

        // Validate recipient selection based on user role
        const isStaff = <?php echo $role === 'staff' ? 'true' : 'false'; ?>;
        
        if (isStaff) {
            // For staff users, we don't need to validate recipient as it's always admin
            const adminId = <?php 
                $admin_query = "SELECT id FROM users WHERE role = 'admin' LIMIT 1";
                $admin_result = $conn->query($admin_query);
                $admin = $admin_result->fetch_assoc();
                echo $admin ? $admin['id'] : 'null';
            ?>;
            
            if (!adminId) {
                alert('Error: Admin user not found');
                return false;
            }
            
            // Add admin ID as receiver
            const receiverInput = document.createElement('input');
            receiverInput.type = 'hidden';
            receiverInput.name = 'receiver_id';
            receiverInput.value = adminId;
            form.appendChild(receiverInput);
        } else {
            // For admin users, validate recipient selection
            const isGroup = document.getElementById('is_group').value === '1';
            if (isGroup) {
                const selectedRecipients = document.querySelectorAll('input[name="recipients[]"]:checked');
                if (selectedRecipients.length === 0) {
                    alert('Please select at least one recipient for the group message');
                    return false;
                }
            } else {
                const recipientSelect = document.querySelector('select[name="receiver_id"]');
                if (!recipientSelect.value) {
                    alert('Please select a recipient');
                    return false;
                }
            }
        }
        
        // Add the submit type (send or draft)
        const submitInput = document.createElement('input');
        submitInput.type = 'hidden';
        submitInput.name = type === 'send' ? 'send_message' : 'save_draft';
        form.appendChild(submitInput);
        
        // Submit the form
        form.submit();
        return true;
    }

    function toggleRecipientSelector(type) {
        const singleRecipient = document.getElementById('singleRecipient');
        const groupRecipients = document.getElementById('groupRecipients');
        const isGroupInput = document.getElementById('is_group');
        const recipientSelect = document.querySelector('select[name="receiver_id"]');
        const recipientsCheckboxes = document.querySelectorAll('input[name="recipients[]"]');
        
        if (type === 'group') {
            singleRecipient.style.display = 'none';
            groupRecipients.style.display = 'block';
            isGroupInput.value = '1';
            recipientSelect.value = ''; // Clear single recipient selection
            document.getElementById('singleRecipientPreview').innerHTML = '';
        } else {
            singleRecipient.style.display = 'block';
            groupRecipients.style.display = 'none';
            isGroupInput.value = '0';
            recipientsCheckboxes.forEach(checkbox => checkbox.checked = false);
            document.getElementById('groupRecipientPreview').innerHTML = '';
        }
    }

    function updateRecipientPreview(select) {
        const preview = document.getElementById('singleRecipientPreview');
        preview.innerHTML = '';
        
        if (select.value) {
            const option = select.options[select.selectedIndex];
            const chip = document.createElement('div');
            chip.className = 'recipient-chip';
            chip.innerHTML = `
                <span>${option.text}</span>
                <span class="remove-recipient" onclick="clearRecipient()">×</span>
            `;
            preview.appendChild(chip);
        }
    }

    function clearRecipient() {
        const select = document.querySelector('select[name="receiver_id"]');
        select.value = '';
        document.getElementById('singleRecipientPreview').innerHTML = '';
    }

    function updateGroupRecipientPreview(checkbox, name) {
        const preview = document.getElementById('groupRecipientPreview');
        
        if (checkbox.checked) {
            const chip = document.createElement('div');
            chip.className = 'recipient-chip';
            chip.innerHTML = `
                <span>${name}</span>
                <span class="remove-recipient" onclick="removeGroupRecipient(this, ${checkbox.value})">×</span>
            `;
            preview.appendChild(chip);
        } else {
            const chips = preview.querySelectorAll('.recipient-chip');
            chips.forEach(chip => {
                if (chip.querySelector('span').textContent === name) {
                    chip.remove();
                }
            });
        }
    }

    function removeGroupRecipient(element, value) {
        const checkbox = document.querySelector(`input[type="checkbox"][value="${value}"]`);
        checkbox.checked = false;
        element.closest('.recipient-chip').remove();
    }

    function handleFileSelect(input) {
        const fileList = document.getElementById('attachmentList');
        fileList.innerHTML = '';
        
        Array.from(input.files).forEach(file => {
            const fileItem = document.createElement('div');
            fileItem.className = 'attachment-chip';
            fileItem.innerHTML = `
                <i class="fas fa-file"></i>
                <span>${file.name}</span>
                <span class="file-size">${formatFileSize(file.size)}</span>
                <i class="fas fa-times remove-file" onclick="removeFile(this)"></i>
            `;
            fileList.appendChild(fileItem);
        });
    }

    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    function removeFile(element) {
        const fileInput = document.querySelector('input[type="file"]');
        fileInput.value = ''; // Reset file input
        element.closest('.attachment-chip').remove();
    }

    // Handle drag and drop
    const dropZone = document.getElementById('fileDropZone');

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, preventDefaults, false);
    });

    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, highlight, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, unhighlight, false);
    });

    function highlight(e) {
        dropZone.classList.add('drag-over');
    }

    function unhighlight(e) {
        dropZone.classList.remove('drag-over');
    }

    dropZone.addEventListener('drop', handleDrop, false);

    function handleDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        const fileInput = dropZone.querySelector('input[type="file"]');
        fileInput.files = files;
        handleFileSelect(fileInput);
    }

    // Message type selection
    document.querySelectorAll('.message-type-option').forEach(option => {
        option.addEventListener('click', function() {
            // Remove active class from all options
            document.querySelectorAll('.message-type-option').forEach(opt => {
                opt.classList.remove('active');
                opt.style.opacity = '0.5';
            });
            
            // Add active class to clicked option
            this.classList.add('active');
            this.style.opacity = '1';
            
            // Update hidden input value
            const messageType = this.getAttribute('data-type');
            document.getElementById('messageType').value = messageType;
        });
    });

    // Initialize without any default selection
    document.addEventListener('DOMContentLoaded', function() {
        // Clear any previous selection
        document.querySelectorAll('.message-type-option').forEach(opt => {
            opt.classList.remove('active');
            opt.style.opacity = '0.5';
        });
        
        // Clear the hidden input
        document.getElementById('messageType').value = '';
    });

    // Close modal when clicking outside
    document.getElementById('composeModal').addEventListener('click', function(e) {
        if (e.target === this) {
            hideComposeForm();
        }
    });

    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('composeModal').classList.contains('active')) {
            hideComposeForm();
        }
    });

    function viewMessage(messageId, tab) {
        fetch(`get_message.php?id=${messageId}&tab=${tab}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const content = document.querySelector('#messageViewContent .message-thread-container');
                    content.innerHTML = '';

                    // Display message thread
                    data.thread.forEach(msg => {
                        const messageDiv = document.createElement('div');
                        messageDiv.className = 'message-thread-item';
                        messageDiv.innerHTML = `
                            <div class="message-header">
                                <div>
                                    <strong>From:</strong> ${msg.sender_name}
                                    <strong>To:</strong> ${msg.receiver_name}
                                </div>
                                <div>${msg.created_at}</div>
                            </div>
                            <div class="message-subject">
                                <strong>${msg.subject}</strong>
                            </div>
                            <div class="message-content">
                                ${msg.message}
                            </div>
                            ${msg.attachments ? renderAttachments(msg.attachments) : ''}
                            ${msg.is_resolved ? `
                                <div class="resolved-badge">
                                    <i class="fas fa-check-circle"></i> Resolved
                                </div>
                            ` : `
                                ${msg.message_type === 'concern' || msg.message_type === 'reminder' ? `
                                    <div class="resolve-action" style="margin-top: 20px;">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="message_id" value="${msg.id}">
                                            <button type="submit" name="resolve_message" class="resolve-btn">
                                                <i class="fas fa-check-circle"></i> Mark as Resolved
                                            </button>
                                        </form>
                                    </div>
                                ` : ''}
                            `}
                        `;
                        content.appendChild(messageDiv);
                    });

                    // Set reply form values
                    document.getElementById('replyParentId').value = messageId;
                    document.getElementById('originalSender').value = data.thread[0].sender_id;

                    // Reset reply form
                    document.getElementById('replyForm').reset();
                    document.getElementById('replyAttachmentList').innerHTML = '';

                    // Show/hide reply form based on user role and tab
                    const replyForm = document.querySelector('#messageViewContent .reply-form');
                    if (tab === 'sent') {
                        replyForm.style.display = 'none';
                    } else {
                        replyForm.style.display = 'block';
                    }

                    showMessageModal();
                } else {
                    alert('Error loading message: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error loading message');
            });
    }

    function renderAttachments(attachments) {
        return `
            <div class="attachment-preview">
                <div style="margin-bottom: 10px;"><strong><i class="fas fa-paperclip"></i> Attachments:</strong></div>
                ${attachments.map(att => `
                    <div class="attachment-item">
                        <i class="fas fa-file attachment-icon"></i>
                        <span class="attachment-name">${att.file_name}</span>
                        <span class="attachment-size">${formatFileSize(att.file_size)}</span>
                        <a href="${att.file_path}" class="attachment-download" download="${att.file_name}">
                            <i class="fas fa-download"></i> Download
                        </a>
                    </div>
                `).join('')}
            </div>
        `;
    }

    function submitReply(form) {
        const formData = new FormData(form);
        
        // Add the current tab to the form data
        const urlParams = new URLSearchParams(window.location.search);
        const currentTab = urlParams.get('tab') || 'inbox';
        formData.append('current_tab', currentTab);
        
        fetch('send_reply.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                hideMessageModal();
                // Refresh the current tab to show the new reply
                window.location.href = `inbox.php?tab=${currentTab}`;
            } else {
                alert('Error sending reply: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error sending reply');
        });
        
        return false; // Prevent form submission
    }

    function showMessageModal() {
        const modal = document.getElementById('messageViewModal');
        const modalContent = modal.querySelector('.modal');
        document.body.classList.add('modal-open');
        modal.classList.add('active');
        setTimeout(() => modalContent.classList.add('active'), 10);
    }

    function hideMessageModal() {
        const modal = document.getElementById('messageViewModal');
        const modalContent = modal.querySelector('.modal');
        document.body.classList.remove('modal-open');
        modalContent.classList.remove('active');
        setTimeout(() => modal.classList.remove('active'), 300);
    }

    // Add event listener to close message modal when clicking outside
    document.getElementById('messageViewModal').addEventListener('click', function(e) {
        if (e.target === this) {
            hideMessageModal();
        }
    });

    // Close message modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('messageViewModal').classList.contains('active')) {
            hideMessageModal();
        }
    });

    // Add these JavaScript functions for table functionality
    let currentPage = 1;
    let pageSize = 10;
    let totalPages = 1;
    let messageData = [];

    function initializeTable() {
        // Get all rows and store them
        const rows = Array.from(document.querySelectorAll('#messageTableBody tr'));
        messageData = rows;
        updateTable();
    }

    function updateTable() {
        const tbody = document.getElementById('messageTableBody');
        const start = (currentPage - 1) * pageSize;
        const end = start + pageSize;
        const paginatedData = messageData.slice(start, end);
        
        tbody.innerHTML = paginatedData.length ? 
            paginatedData.map(row => row.outerHTML).join('') :
            '<tr><td colspan="9" class="no-data">No data available in table</td></tr>';
        
        updatePagination();
        updateTableInfo();
    }

    function updatePagination() {
        totalPages = Math.ceil(messageData.length / pageSize);
        const pagination = document.getElementById('paginationNumbers');
        let paginationHtml = '';
        
        for (let i = 1; i <= totalPages; i++) {
            paginationHtml += `
                <span class="page-number ${i === currentPage ? 'active' : ''}"
                      onclick="goToPage(${i})">${i}</span>
            `;
        }
        
        pagination.innerHTML = paginationHtml;
    }

    function updateTableInfo() {
        const start = messageData.length ? (currentPage - 1) * pageSize + 1 : 0;
        const end = Math.min(start + pageSize - 1, messageData.length);
        const total = messageData.length;
        
        document.getElementById('startEntry').textContent = start;
        document.getElementById('endEntry').textContent = end;
        document.getElementById('totalEntries').textContent = total;
    }

    function changePageSize(size) {
        pageSize = parseInt(size);
        currentPage = 1;
        updateTable();
    }

    function searchMessages(query) {
        const allRows = Array.from(document.querySelectorAll('#messageTableBody tr'));
        messageData = allRows.filter(row => {
            const text = row.textContent.toLowerCase();
            return text.includes(query.toLowerCase());
        });
        
        currentPage = 1;
        updateTable();
    }

    function goToPage(page) {
        currentPage = page;
        updateTable();
    }

    function previousPage() {
        if (currentPage > 1) {
            currentPage--;
            updateTable();
        }
    }

    function nextPage() {
        if (currentPage < totalPages) {
            currentPage++;
            updateTable();
        }
    }

    // Initialize table when page loads
    document.addEventListener('DOMContentLoaded', initializeTable);

    // Add this new function to handle resolve button click
    function resolveMessage(messageId) {
        if (confirm('Are you sure you want to mark this message as resolved?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="message_id" value="${messageId}">
                <input type="hidden" name="resolve_message" value="1">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }

    // Update the compose modal HTML for staff users
    <?php if ($role === 'staff'): ?>
    document.addEventListener('DOMContentLoaded', function() {
        const composeForm = document.getElementById('composeForm');
        if (composeForm) {
            // Remove recipient selection for staff users
            const recipientGroup = composeForm.querySelector('.form-group:has(select[name="receiver_id"])');
            if (recipientGroup) {
                recipientGroup.style.display = 'none';
            }
            
            // Add hidden input for admin as receiver
            const adminId = <?php 
                $admin_query = "SELECT id FROM users WHERE role = 'admin' LIMIT 1";
                $admin_result = $conn->query($admin_query);
                $admin = $admin_result->fetch_assoc();
                echo $admin ? $admin['id'] : 'null';
            ?>;
            
            if (adminId) {
                const receiverInput = document.createElement('input');
                receiverInput.type = 'hidden';
                receiverInput.name = 'receiver_id';
                receiverInput.value = adminId;
                composeForm.appendChild(receiverInput);
            }
        }
    });
    <?php endif; ?>
    </script>
</body>
</html>