<?php
// Database connection
$host = "localhost";
$dbname = "accountant";
$db_username = "root";
$db_password = "";

$conn = new mysqli($host, $db_username, $db_password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Today's date
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$tomorrow = date('Y-m-d', strtotime('+1 day'));

// Sample tasks
$tasks = [
    [
        'title' => 'Complete monthly report',
        'description' => 'Finish the financial report for this month',
        'status' => 'pending',
        'priority' => 'high',
        'due_date' => $today . ' 17:00:00',
        'created_by' => 1,
        'assigned_to' => 1
    ],
    [
        'title' => 'Client meeting preparation',
        'description' => 'Prepare documents for client meeting',
        'status' => 'completed',
        'priority' => 'medium',
        'due_date' => $today . ' 10:00:00',
        'completed_at' => date('Y-m-d H:i:s'),
        'created_by' => 1,
        'assigned_to' => 1
    ],
    [
        'title' => 'Tax filing deadline',
        'description' => 'Submit tax documents before deadline',
        'status' => 'pending',
        'priority' => 'high',
        'due_date' => $yesterday . ' 23:59:59',
        'created_by' => 1,
        'assigned_to' => 1
    ],
    [
        'title' => 'Team meeting',
        'description' => 'Weekly team progress meeting',
        'status' => 'pending',
        'priority' => 'medium',
        'due_date' => $tomorrow . ' 14:00:00',
        'created_by' => 1,
        'assigned_to' => 1
    ]
];

// Insert tasks
$stmt = $conn->prepare("INSERT INTO tasks (title, description, status, priority, due_date, completed_at, created_by, assigned_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

foreach ($tasks as $task) {
    $completed_at = isset($task['completed_at']) ? $task['completed_at'] : null;
    $stmt->bind_param("ssssssii", 
        $task['title'], 
        $task['description'], 
        $task['status'], 
        $task['priority'], 
        $task['due_date'], 
        $completed_at, 
        $task['created_by'], 
        $task['assigned_to']
    );
    
    if ($stmt->execute()) {
        echo "Added task: " . $task['title'] . "<br>";
    } else {
        echo "Error adding task: " . $stmt->error . "<br>";
    }
}

$stmt->close();
$conn->close();

echo "<p>Done! <a href='dashboard.php'>Return to Dashboard</a></p>";
?>
