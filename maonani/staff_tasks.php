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

// Check if user is logged in and has required session data
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    // Redirect to login if session is invalid
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Verify user exists and get their details
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = ?");
$stmt->bind_param("is", $user_id, $role);
$stmt->execute();
$result = $stmt->get_result();

if (!$result->num_rows) {
    // User not found or role mismatch
    session_destroy();
    header("Location: login.php");
    exit();
}

$user = $result->fetch_assoc();
$stmt->close();

// Get current month and year
$current_month_offset = 0;
if (isset($_GET['month'])) {
    if ($_GET['month'] === 'prev') {
        $current_month_offset = -1;
    } elseif ($_GET['month'] === 'next') {
        $current_month_offset = 1;
    }
}

$current_date = new DateTime();
if ($current_month_offset !== 0) {
    $current_date->modify($current_month_offset . ' month');
}

$current_month = $current_date->format('Y-m');
$days_in_month = $current_date->format('t');
$month_name = $current_date->format('F');
$year = $current_date->format('Y');
$first_day_of_month = date('N', strtotime($current_month . "-01"));

// Fetch assigned tasks with more details
$tasks_stmt = $conn->prepare("
    SELECT t.*, 
           u.first_name as assigned_by_name,
           u.last_name as assigned_by_lastname,
           GROUP_CONCAT(DISTINCT ta.file_name) as attachment_names
    FROM tasks t
    LEFT JOIN users u ON t.created_by = u.id
    LEFT JOIN task_attachments ta ON t.id = ta.task_id
    WHERE t.assigned_to = ?
    GROUP BY t.id
    ORDER BY 
        CASE 
            WHEN t.status = 'pending' AND t.due_date < NOW() THEN 1
            WHEN t.status = 'pending' THEN 2
            WHEN t.status = 'completed' THEN 3
        END,
        t.due_date ASC
");
$tasks_stmt->bind_param("i", $user_id);
$tasks_stmt->execute();
$tasks_result = $tasks_stmt->get_result();
$tasks_stmt->close();

// Count tasks by status
$task_counts = [
    'total' => 0,
    'pending' => 0,
    'completed' => 0,
    'overdue' => 0
];

$all_tasks = [];
$calendar_tasks = [];

while ($task = $tasks_result->fetch_assoc()) {
    $all_tasks[] = $task;
    $task_counts['total']++;
    
    // Add to calendar data
    $task_date = date('Y-m-d', strtotime($task['due_date']));
    $task_day = date('j', strtotime($task['due_date']));
    
    if (!isset($calendar_tasks[$task_day])) {
        $calendar_tasks[$task_day] = [];
    }
    
    $calendar_tasks[$task_day][] = [
        'id' => $task['id'],
        'title' => $task['title'],
        'status' => $task['status'],
        'due_date' => $task['due_date'],
        'is_overdue' => strtotime($task['due_date']) < time() && $task['status'] !== 'completed'
    ];
    
    if ($task['status'] === 'completed') {
        $task_counts['completed']++;
    } else {
        if (strtotime($task['due_date']) < time()) {
            $task_counts['overdue']++;
        }
        $task_counts['pending']++;
    }
}

// Get month name and year for calendar

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Tasks Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4a90e2;
            --secondary-color: #2c3e50;
            --success-color: #2ecc71;
            --warning-color: #f1c40f;
            --danger-color: #e74c3c;
            --light-color: #f5f5f5;
            --dark-color: #333;
            --border-color: #ddd;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: var(--dark-color);
            background-color: #f0f2f5;
        }
        
        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .back-button {
            display: inline-block;
            margin-bottom: 20px;
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
        }
        
        .back-button:hover {
            text-decoration: underline;
        }
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 20px;
        }
        
        .user-welcome {
            display: flex;
            align-items: center;
        }
        
        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 24px;
            margin-right: 15px;
        }
        
        .welcome-text h1 {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }
        
        .welcome-text p {
            color: #666;
        }
        
        .task-stats {
            display: flex;
            gap: 15px;
        }
        
        .stat-card {
            padding: 15px;
            border-radius: 8px;
            color: white;
            text-align: center;
            min-width: 120px;
        }
        
        .stat-card i {
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .stat-number {
            font-size: 1.8rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.9rem;
        }
        
        .pending {
            background-color: var(--warning-color);
            color: #000;
        }
        
        .completed {
            background-color: var(--success-color);
        }
        
        .overdue {
            background-color: var(--danger-color);
        }
        
        .task-section {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            overflow: hidden;
        }
        
        .section-header {
            background-color: var(--primary-color);
            color: white;
            padding: 15px 20px;
            display: flex;
            align-items: center;
        }
        
        .section-header i {
            font-size: 20px;
            margin-right: 10px;
        }
        
        .section-header h2 {
            font-size: 1.2rem;
            font-weight: 500;
        }
        
        .calendar-container {
            padding: 20px;
        }
        
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .calendar-title {
            font-size: 1.5rem;
            font-weight: 500;
        }
        
        .calendar-nav {
            display: flex;
            gap: 10px;
        }
        
        .calendar-nav button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 4px;
            padding: 8px 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .calendar-nav button:hover {
            background-color: #3a7bc8;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 10px;
        }
        
        .calendar-weekday {
            text-align: center;
            font-weight: 500;
            padding: 10px;
            background-color: #f5f5f5;
            border-radius: 4px;
        }
        
        .calendar-day {
            min-height: 100px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 5px;
            position: relative;
        }
        
        .calendar-day.empty {
            background-color: #f9f9f9;
        }
        
        .calendar-day.today {
            border: 2px solid var(--primary-color);
        }
        
        .day-number {
            position: absolute;
            top: 5px;
            right: 5px;
            width: 25px;
            height: 25px;
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: 500;
            border-radius: 50%;
        }
        
        .today .day-number {
            background-color: var(--primary-color);
            color: white;
        }
        
        .calendar-task {
            margin-top: 25px;
            margin-bottom: 5px;
            padding: 5px;
            border-radius: 4px;
            font-size: 0.8rem;
            cursor: pointer;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .calendar-task.pending {
            background-color: rgba(241, 196, 15, 0.2);
            border-left: 3px solid var(--warning-color);
            color: #000;
        }
        
        .calendar-task.completed {
            background-color: rgba(46, 204, 113, 0.2);
            border-left: 3px solid var(--success-color);
            color: #000;
        }
        
        .calendar-task.overdue {
            background-color: rgba(231, 76, 60, 0.2);
            border-left: 3px solid var(--danger-color);
            color: #000;
        }
        
        .task-list {
            padding: 20px;
        }
        
        .task-item {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            background-color: white;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .task-item.completed {
            border-left: 4px solid var(--success-color);
        }
        
        .task-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .task-title {
            font-weight: 500;
            font-size: 1.1rem;
        }
        
        .task-status {
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .status-pending {
            background-color: var(--warning-color);
            color: #000;
        }
        
        .status-completed {
            background-color: var(--success-color);
            color: white;
        }
        
        .status-overdue {
            background-color: var(--danger-color);
            color: white;
        }
        
        .task-meta {
            display: flex;
            gap: 15px;
            margin-bottom: 10px;
            font-size: 0.9rem;
            color: #666;
        }
        
        .task-description {
            color: #666;
            font-size: 0.95rem;
            margin: 0.5rem 0;
        }
        
        .task-attachments {
            font-size: 0.9rem;
            color: #666;
            margin-top: 10px;
        }
        
        .task-comments {
            font-size: 0.9rem;
            color: #666;
            margin-top: 10px;
            font-style: italic;
        }
        
        .btn {
            display: inline-block;
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            background-color: var(--primary-color);
            color: white;
            text-decoration: none;
            font-size: 0.9rem;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .btn:hover {
            background-color: darken(var(--primary-color), 10%);
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <a href="dashboard.php" class="back-button">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <div class="dashboard-header">
            <div class="user-welcome">
                <div class="user-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <div class="welcome-text">
                    <h1>Welcome, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>!</h1>
                    <p>Here's an overview of your tasks</p>
                </div>
            </div>

            <div class="task-stats">
                <div class="stat-card pending">
                    <i class="fas fa-tasks"></i>
                    <div class="stat-number"><?php echo $task_counts['pending']; ?></div>
                    <div class="stat-label">Pending Tasks</div>
                </div>
                <div class="stat-card completed">
                    <i class="fas fa-check-circle"></i>
                    <div class="stat-number"><?php echo $task_counts['completed']; ?></div>
                    <div class="stat-label">Completed Tasks</div>
                </div>
                <div class="stat-card overdue">
                    <i class="fas fa-exclamation-circle"></i>
                    <div class="stat-number"><?php echo $task_counts['overdue']; ?></div>
                    <div class="stat-label">Overdue Tasks</div>
                </div>
            </div>
        </div>

        <div class="tasks-container">
            <!-- Calendar View -->
            <div class="task-section">
                <div class="section-header">
                    <i class="fas fa-calendar"></i>
                    <h2>My Task Calendar</h2>
                </div>
                <div class="calendar-container">
                    <div class="calendar-header">
                        <div class="calendar-title"><?php echo $month_name . ' ' . $year; ?></div>
                        <div class="calendar-nav">
                            <button id="prevMonth"><i class="fas fa-chevron-left"></i> Previous</button>
                            <button id="nextMonth">Next <i class="fas fa-chevron-right"></i></button>
                        </div>
                    </div>
                    
                    <div class="calendar-grid">
                        <!-- Weekday headers -->
                        <div class="calendar-weekday">Mon</div>
                        <div class="calendar-weekday">Tue</div>
                        <div class="calendar-weekday">Wed</div>
                        <div class="calendar-weekday">Thu</div>
                        <div class="calendar-weekday">Fri</div>
                        <div class="calendar-weekday">Sat</div>
                        <div class="calendar-weekday">Sun</div>
                        
                        <!-- Empty cells for days before the first day of month -->
                        <?php for ($i = 1; $i < $first_day_of_month; $i++): ?>
                            <div class="calendar-day empty"></div>
                        <?php endfor; ?>
                        
                        <!-- Calendar days -->
                        <?php 
                        $today_day = date('j');
                        $today_month = date('n');
                        $today_year = date('Y');
                        
                        for ($day = 1; $day <= $days_in_month; $day++): 
                            $is_today = ($day == $today_day && date('n') == $today_month && date('Y') == $today_year);
                        ?>
                            <div class="calendar-day <?php echo $is_today ? 'today' : ''; ?>">
                                <div class="day-number"><?php echo $day; ?></div>
                                
                                <?php if (isset($calendar_tasks[$day])): ?>
                                    <?php foreach ($calendar_tasks[$day] as $task): ?>
                                        <div class="calendar-task <?php 
                                            if ($task['status'] === 'completed') {
                                                echo 'completed';
                                            } elseif ($task['is_overdue']) {
                                                echo 'overdue';
                                            } else {
                                                echo 'pending';
                                            }
                                        ?>" onclick="window.location.href='view_task.php?id=<?php echo $task['id']; ?>';">
                                            <?php echo htmlspecialchars($task['title']); ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                        
                        <!-- Empty cells for days after the last day of month -->
                        <?php 
                        $last_day_weekday = date('N', strtotime("$current_month-$days_in_month"));
                        $remaining_cells = 7 - $last_day_weekday;
                        
                        if ($remaining_cells < 7):
                            for ($i = 0; $i < $remaining_cells; $i++): 
                        ?>
                            <div class="calendar-day empty"></div>
                        <?php 
                            endfor;
                        endif;
                        ?>
                    </div>
                </div>
            </div>
            
            <!-- Upcoming Tasks Section -->
            <div class="task-section">
                <div class="section-header">
                    <i class="fas fa-clock"></i>
                    <h2>Upcoming Tasks</h2>
                </div>
                <div class="task-list">
                    <?php 
                    $upcoming_count = 0;
                    foreach ($all_tasks as $task): 
                        if ($task['status'] === 'pending' && $upcoming_count < 5):
                            $upcoming_count++;
                    ?>
                        <div class="task-item" data-id="<?php echo $task['id']; ?>">
                            <div class="task-header" onclick="window.location.href='view_task.php?id=<?php echo $task['id']; ?>';" style="cursor: pointer;">
                                <div class="task-title"><?php echo htmlspecialchars($task['title']); ?></div>
                                <span class="task-status <?php echo strtotime($task['due_date']) < time() ? 'status-overdue' : 'status-pending'; ?>">
                                    <?php echo strtotime($task['due_date']) < time() ? 'Overdue' : 'Pending'; ?>
                                </span>
                            </div>
                            <div class="task-meta">
                                <span><i class="fas fa-calendar"></i> Due: <?php echo date('M j, Y', strtotime($task['due_date'])); ?></span>
                                <span><i class="fas fa-user"></i> From: <?php echo htmlspecialchars($task['assigned_by_name'] . ' ' . $task['assigned_by_lastname']); ?></span>
                            </div>
                        </div>
                    <?php 
                        endif;
                    endforeach; 
                    
                    if ($upcoming_count === 0):
                    ?>
                        <p style="padding: 20px; text-align: center; color: #666;">No upcoming tasks!</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Calendar navigation
        document.getElementById('prevMonth').addEventListener('click', function() {
            window.location.href = 'staff_tasks.php?month=prev';
        });
        
        document.getElementById('nextMonth').addEventListener('click', function() {
            window.location.href = 'staff_tasks.php?month=next';
        });
    </script>
</body>
</html>
