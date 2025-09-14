<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

if (!isset($_GET['date'])) {
    echo json_encode(['error' => 'Missing date parameter']);
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

// Get task counts for the specified date
$query = "
    SELECT 
        SUM(CASE 
            WHEN status = 'pending' AND due_date > NOW() THEN 1 
            ELSE 0 
        END) as pending,
        SUM(CASE 
            WHEN status = 'completed' THEN 1 
            ELSE 0 
        END) as completed,
        SUM(CASE 
            WHEN status = 'pending' AND due_date <= NOW() THEN 1 
            ELSE 0 
        END) as overdue
    FROM tasks 
    WHERE DATE(due_date) = ?
    AND (assigned_to = ? OR created_by = ?)";

$stmt = $conn->prepare($query);
$stmt->bind_param('sii', $date, $user_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    // Convert null values to 0
    $row['pending'] = (int)$row['pending'];
    $row['completed'] = (int)$row['completed'];
    $row['overdue'] = (int)$row['overdue'];
    $row['total'] = $row['pending'] + $row['completed'] + $row['overdue'];
    echo json_encode($row);
} else {
    echo json_encode([
        'pending' => 0,
        'completed' => 0,
        'overdue' => 0,
        'total' => 0
    ]);
}

$stmt->close();
$conn->close();
?>
