<?php
session_start();

// Set header to always return JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Get date parameter
if (!isset($_GET['date'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Date parameter is required']);
    exit();
}

$date = $_GET['date'];
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

try {
    // Database connection parameters
    $host = "localhost";
    $dbname = "accountant";
    $db_username = "root";
    $db_password = "";

    $conn = new mysqli($host, $db_username, $db_password, $dbname);

    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Debug: Log the received date
    error_log("Received date: " . $date);

    // First, let's check if there are any tasks for this date
    $check_query = "SELECT COUNT(*) as count, due_date FROM tasks WHERE DATE(due_date) = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("s", $date);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result()->fetch_assoc();
    
    // Debug: Log the check result
    error_log("Check result: " . print_r($check_result, true));
    
    if ($check_result['count'] == 0) {
        // Debug: Log that no tasks were found
        error_log("No tasks found for date: " . $date);
        echo json_encode([]);
        exit();
    }

    // Fetch tasks for the selected date
    $query = "SELECT t.*, 
              u1.first_name as creator_first_name, u1.last_name as creator_last_name,
              u2.first_name as assigned_first_name, u2.last_name as assigned_last_name,
              ts.status as submission_status, ts.submitted_at, ts.review_notes, ts.id as submission_id,
              GROUP_CONCAT(DISTINCT ta.file_name ORDER BY ta.id SEPARATOR '||') as attachment_names,
              GROUP_CONCAT(DISTINCT ta.file_path ORDER BY ta.id SEPARATOR '||') as attachment_paths,
              CASE 
                WHEN t.status = 'completed' THEN t.completed_at
                ELSE NULL 
              END as completion_date
              FROM tasks t
              LEFT JOIN users u1 ON t.created_by = u1.id
              LEFT JOIN users u2 ON t.assigned_to = u2.id
              LEFT JOIN task_submissions ts ON t.id = ts.task_id AND ts.submitted_by = t.assigned_to
              LEFT JOIN task_attachments ta ON t.id = ta.task_id
              WHERE DATE(t.due_date) = ?
              GROUP BY t.id, ts.id
              ORDER BY t.status ASC, t.created_at DESC";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param("s", $date);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }

    $result = $stmt->get_result();

    $tasks = [];
    while ($row = $result->fetch_assoc()) {
        // Debug: Log each task as it's processed
        error_log("Processing task: " . print_r($row, true));
        
        // Process attachments from GROUP_CONCAT
        $attachments = [];
        if (!empty($row['attachment_names']) && !empty($row['attachment_paths'])) {
            $names = explode('||', $row['attachment_names']);
            $paths = explode('||', $row['attachment_paths']);
            for ($i = 0; $i < count($names); $i++) {
                $attachments[] = [
                    'file_name' => $names[$i],
                    'file_path' => $paths[$i]
                ];
            }
        }
        
        // Get submission attachments if exists
        $submission_attachments = [];
        if ($row['submission_status'] && $row['submission_id']) {
            try {
                $sub_att_query = "SELECT * FROM submission_attachments WHERE submission_id = ?";
                $sub_att_stmt = $conn->prepare($sub_att_query);
                if (!$sub_att_stmt) {
                    throw new Exception("Failed to prepare submission attachments query");
                }
                $sub_att_stmt->bind_param("i", $row['submission_id']);
                if (!$sub_att_stmt->execute()) {
                    throw new Exception("Failed to execute submission attachments query");
                }
                $submission_attachments = $sub_att_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $sub_att_stmt->close();
            } catch (Exception $e) {
                error_log("Error fetching submission attachments: " . $e->getMessage());
                $submission_attachments = [];
            }
        }
        
        $tasks[] = [
            'id' => $row['id'],
            'title' => $row['title'],
            'description' => $row['description'],
            'due_date' => $row['due_date'],
            'status' => $row['status'],
            'completion_date' => $row['completion_date'],
            'assigned_to' => $row['assigned_first_name'] . ' ' . $row['assigned_last_name'],
            'created_by' => $row['creator_first_name'] . ' ' . $row['creator_last_name'],
            'submission' => $row['submission_status'] ? [
                'id' => $row['submission_id'],
                'status' => $row['submission_status'],
                'submitted_at' => $row['submitted_at'],
                'review_notes' => $row['review_notes'],
                'attachments' => $submission_attachments
            ] : null,
            'attachments' => $attachments
        ];
    }

    // Debug: Log the tasks found
    error_log("Tasks found: " . print_r($tasks, true));
    echo json_encode($tasks);

} catch (Exception $e) {
    error_log("Error in get_tasks.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
} 