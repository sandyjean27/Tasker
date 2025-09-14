<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

if (!isset($_GET['date'])) {
    echo json_encode(['error' => 'No date provided']);
    exit();
}

// Database connection
$host = "localhost";
$dbname = "accountant";
$db_username = "root";
$db_password = "";

$conn = new mysqli($host, $db_username, $db_password, $dbname);

if ($conn->connect_error) {
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

$date = $_GET['date'];
$user_id = $_SESSION['user_id'];

// Get all tasks for the selected date
$query = "SELECT t.*, 
          CONCAT(u.first_name, ' ', u.last_name) as assigned_to_name
          FROM tasks t
          LEFT JOIN users u ON t.assigned_to = u.id
          WHERE DATE(t.due_date) = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("s", $date);
$stmt->execute();
$result = $stmt->get_result();

$pending = [];
$completed = [];
$overdue = [];

while ($task = $result->fetch_assoc()) {
    // Create a clean task object with only the needed fields
    $task_data = [
        'id' => $task['id'],
        'title' => $task['title'],
        'description' => $task['description'],
        'due_date' => $task['due_date'],
        'assigned_to_name' => $task['assigned_to_name'] ?: 'Unassigned',
        'priority' => $task['priority'] ?: 'medium'
    ];

    if ($task['status'] === 'completed') {
        $task_data['completed_at'] = $task['completed_at'];
        $completed[] = $task_data;
    } else if ($task['status'] === 'pending') {
        if (strtotime($task['due_date']) <= time()) {
            $overdue[] = $task_data;
        } else {
            $pending[] = $task_data;
        }
    }
}

// Debug output to error log
error_log("Date: " . $date);
error_log("Tasks found: " . $result->num_rows);
error_log("Pending: " . count($pending));
error_log("Completed: " . count($completed));
error_log("Overdue: " . count($overdue));

// Return the tasks
echo json_encode([
    'pending' => $pending,
    'completed' => $completed,
    'overdue' => $overdue
]);

$stmt->close();
$conn->close();
?>
