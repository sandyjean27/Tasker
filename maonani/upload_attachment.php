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

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Check if task ID is provided
if (!isset($_POST['task_id'])) {
    http_response_code(400);
    exit('Task ID is required');
}

$task_id = (int)$_POST['task_id'];

// Verify user has permission to upload attachments
$task_query = "SELECT created_by, assigned_to FROM tasks WHERE id = ?";
$task_stmt = $conn->prepare($task_query);
$task_stmt->bind_param("i", $task_id);
$task_stmt->execute();
$task_result = $task_stmt->get_result();
$task_data = $task_result->fetch_assoc();
$task_stmt->close();

if (!$task_data || ($role !== 'admin' && $user_id !== $task_data['assigned_to'])) {
    http_response_code(403);
    exit('Unauthorized');
}

// Check if file was uploaded
if (!isset($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    exit('No file uploaded or upload error');
}

$file = $_FILES['attachment'];
$file_name = $file['name'];
$file_type = $file['type'];
$file_size = $file['size'];
$file_tmp = $file['tmp_name'];

// Create uploads directory if it doesn't exist
$upload_dir = 'uploads/task_attachments/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Generate unique filename
$file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
$unique_filename = uniqid() . '_' . time() . '.' . $file_extension;
$file_path = $upload_dir . $unique_filename;

// Move uploaded file
if (!move_uploaded_file($file_tmp, $file_path)) {
    http_response_code(500);
    exit('Failed to save file');
}

// Save attachment record to database
$stmt = $conn->prepare("INSERT INTO task_attachments (task_id, file_name, file_path, file_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("isssii", $task_id, $file_name, $file_path, $file_type, $file_size, $user_id);
$stmt->execute();
$stmt->close();

// Return success response
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'file_name' => $file_name,
    'file_path' => $file_path
]); 