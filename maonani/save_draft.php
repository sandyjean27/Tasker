<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$host = "localhost";
$dbname = "accountant";
$username = "root";
$password = "";

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$user_id = $_SESSION['user_id'];
$subject = isset($_POST['subject']) ? $conn->real_escape_string($_POST['subject']) : '';
$message = isset($_POST['message']) ? $conn->real_escape_string($_POST['message']) : '';
$message_type = isset($_POST['message_type']) ? $conn->real_escape_string($_POST['message_type']) : 'casual';
$receiver_id = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : null;
$is_autosave = isset($_POST['autosave']) && $_POST['autosave'] === '1';

// Start transaction
$conn->begin_transaction();

try {
    // Check if there's an existing draft for this user
    $check_draft = $conn->prepare("SELECT id FROM messages WHERE sender_id = ? AND is_draft = 1 ORDER BY created_at DESC LIMIT 1");
    $check_draft->bind_param("i", $user_id);
    $check_draft->execute();
    $existing_draft = $check_draft->get_result()->fetch_assoc();
    $check_draft->close();

    if ($existing_draft && $is_autosave) {
        // Update existing draft
        $update_stmt = $conn->prepare("UPDATE messages SET subject = ?, message = ?, message_type = ?, receiver_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $update_stmt->bind_param("sssii", $subject, $message, $message_type, $receiver_id, $existing_draft['id']);
        $update_stmt->execute();
        $update_stmt->close();
        $draft_id = $existing_draft['id'];
    } else {
        // Insert new draft
        $insert_stmt = $conn->prepare("INSERT INTO messages (subject, message, sender_id, receiver_id, message_type, is_draft) VALUES (?, ?, ?, ?, ?, 1)");
        $insert_stmt->bind_param("ssiis", $subject, $message, $user_id, $receiver_id, $message_type);
        $insert_stmt->execute();
        $draft_id = $conn->insert_id;
        $insert_stmt->close();
    }

    // Handle file attachments
    if (!empty($_FILES['attachments']['name'][0])) {
        $upload_dir = 'uploads/messages';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $attachment_stmt = $conn->prepare("INSERT INTO message_attachments (message_id, file_name, file_path, file_size, file_type) VALUES (?, ?, ?, ?, ?)");
        
        foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['attachments']['error'][$key] === 0) {
                $file_name = $_FILES['attachments']['name'][$key];
                $file_type = $_FILES['attachments']['type'][$key];
                $file_size = $_FILES['attachments']['size'][$key];
                
                // Generate unique filename
                $unique_name = uniqid() . '_' . $file_name;
                $file_path = $upload_dir . '/' . $unique_name;
                
                if (move_uploaded_file($tmp_name, $file_path)) {
                    $attachment_stmt->bind_param("issis", $draft_id, $file_name, $file_path, $file_size, $file_type);
                    $attachment_stmt->execute();
                }
            }
        }
        $attachment_stmt->close();
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => $is_autosave ? 'Draft autosaved' : 'Draft saved successfully']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Error saving draft: ' . $e->getMessage()]);
}

$conn->close();
?> 