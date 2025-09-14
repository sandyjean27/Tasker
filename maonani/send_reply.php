<?php
session_start();
require_once 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$user_id = $_SESSION['user_id'];
$parent_id = (int)$_POST['parent_id'];
$original_sender = (int)$_POST['original_sender'];
$message = $_POST['message'];

// Start transaction
$conn->begin_transaction();

try {
    // Get original message details
    $orig_query = "SELECT subject, message_type FROM messages WHERE id = ?";
    $orig_stmt = $conn->prepare($orig_query);
    $orig_stmt->bind_param("i", $parent_id);
    $orig_stmt->execute();
    $original = $orig_stmt->get_result()->fetch_assoc();
    
    // Create reply message
    $reply_stmt = $conn->prepare("INSERT INTO messages (subject, message, sender_id, receiver_id, parent_id, message_type) 
                                VALUES (?, ?, ?, ?, ?, ?)");
    
    $subject = "Re: " . $original['subject'];
    $receiver_id = ($user_id === $original_sender) ? $_SESSION['admin_id'] : $original_sender;
    
    $reply_stmt->bind_param("ssiiss", $subject, $message, $user_id, $receiver_id, $parent_id, $original['message_type']);
    $reply_stmt->execute();
    $message_id = $conn->insert_id;
    
    // Handle attachments if any
    if (!empty($_FILES['attachments']['name'][0])) {
        $upload_dir = 'uploads/messages/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $att_stmt = $conn->prepare("INSERT INTO message_attachments (message_id, file_name, file_path, file_size, file_type) 
                                  VALUES (?, ?, ?, ?, ?)");
        
        foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['attachments']['error'][$key] === 0) {
                $file_name = $_FILES['attachments']['name'][$key];
                $file_type = $_FILES['attachments']['type'][$key];
                $file_size = $_FILES['attachments']['size'][$key];
                
                $unique_name = uniqid() . '_' . $file_name;
                $file_path = $upload_dir . $unique_name;
                
                if (move_uploaded_file($tmp_name, $file_path)) {
                    $att_stmt->bind_param("issis", $message_id, $file_name, $file_path, $file_size, $file_type);
                    $att_stmt->execute();
                }
            }
        }
    }
    
    // Create notification for recipient
    $notify_stmt = $conn->prepare("INSERT INTO notifications (user_id, message_id, type) VALUES (?, ?, 'new_reply')");
    $notify_stmt->bind_param("ii", $receiver_id, $message_id);
    $notify_stmt->execute();
    
    $conn->commit();
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?> 