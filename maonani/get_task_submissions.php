<?php
session_start();
require_once 'db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Get task ID from request
$task_id = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;

if (!$task_id) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid task ID']);
    exit();
}

// Check if user has access to this task
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

if ($role === 'admin') {
    // Admins can view all task submissions
    $access_check = true;
} else {
    // Staff can only view their own task submissions
    $task_check = $conn->prepare("SELECT id FROM tasks WHERE id = ? AND (assigned_to = ? OR created_by = ?)");
    $task_check->bind_param("iii", $task_id, $user_id, $user_id);
    $task_check->execute();
    $task_result = $task_check->get_result();
    $access_check = $task_result->num_rows > 0;
}

if (!$access_check) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'You do not have permission to view this task']);
    exit();
}

// Get task submissions
$submissions_query = "SELECT ts.*, 
                     u1.first_name as submitter_first_name, u1.last_name as submitter_last_name,
                     u2.first_name as reviewer_first_name, u2.last_name as reviewer_last_name
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
    // Format submission data
    $submissions[] = [
        'id' => $submission['id'],
        'task_id' => $submission['task_id'],
        'submitted_by' => $submission['submitted_by'],
        'submitter_name' => $submission['submitter_first_name'] . ' ' . $submission['submitter_last_name'],
        'submission_text' => $submission['submission_text'],
        'status' => $submission['status'],
        'submitted_at' => $submission['submitted_at'],
        'reviewed_by' => $submission['reviewed_by'],
        'reviewer_name' => $submission['reviewer_first_name'] ? $submission['reviewer_first_name'] . ' ' . $submission['reviewer_last_name'] : null,
        'reviewed_at' => $submission['reviewed_at'],
        'review_notes' => $submission['review_notes']
    ];
}

// Return submissions as JSON
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'submissions' => $submissions
]);
?>
