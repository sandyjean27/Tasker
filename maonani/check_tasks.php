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

// Check if there are any tasks
$result = $conn->query("SELECT COUNT(*) as count FROM tasks");
$row = $result->fetch_assoc();
echo "Total tasks in database: " . $row['count'] . "<br>";

// Show sample tasks
$result = $conn->query("SELECT id, title, status, due_date, priority FROM tasks LIMIT 5");
echo "<h3>Sample tasks:</h3>";
if ($result->num_rows > 0) {
    echo "<ul>";
    while ($row = $result->fetch_assoc()) {
        echo "<li>ID: " . $row['id'] . ", Title: " . $row['title'] . ", Status: " . $row['status'] . ", Due: " . $row['due_date'] . ", Priority: " . $row['priority'] . "</li>";
    }
    echo "</ul>";
} else {
    echo "<p>No tasks found in the database.</p>";
    
    // Add sample tasks if none exist
    echo "<h3>Adding sample tasks...</h3>";
    
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
            'priority' => 'High',
            'due_date' => $today . ' 17:00:00',
            'created_by' => 1,
            'assigned_to' => 1
        ],
        [
            'title' => 'Client meeting preparation',
            'description' => 'Prepare documents for client meeting',
            'status' => 'completed',
            'priority' => 'Medium',
            'due_date' => $today . ' 10:00:00',
            'completed_at' => $today . ' 09:30:00',
            'created_by' => 1,
            'assigned_to' => 1
        ],
        [
            'title' => 'Tax filing deadline',
            'description' => 'Submit tax documents before deadline',
            'status' => 'pending',
            'priority' => 'High',
            'due_date' => $yesterday . ' 23:59:59',
            'created_by' => 1,
            'assigned_to' => 1
        ],
        [
            'title' => 'Team meeting',
            'description' => 'Weekly team progress meeting',
            'status' => 'pending',
            'priority' => 'Medium',
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
            echo "<p>Added task: " . $task['title'] . "</p>";
        } else {
            echo "<p>Error adding task: " . $stmt->error . "</p>";
        }
    }
    
    $stmt->close();
}

$conn->close();
?>
