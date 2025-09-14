<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Connect to database
$host = "localhost"; 
$dbname = "accountant"; 
$db_username = "root"; 
$db_password = ""; 

$conn = new mysqli($host, $db_username, $db_password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch user details first
$user_stmt = $conn->prepare("SELECT username, role, first_name, last_name FROM users WHERE id = ?");
if (!$user_stmt) {
    die("Error preparing user query: " . $conn->error);
}

$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();

if ($user_result->num_rows === 0) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$user_data = $user_result->fetch_assoc();
$username = $user_data['username'];
$role = $user_data['role'];
$user_stmt->close();

// Store in session if not already set
$_SESSION['username'] = $username;
$_SESSION['role'] = $role;

// Fetch tasks based on role
if ($role === 'admin') {
    // Fetch all tasks for admin
    $tasks_query = "SELECT t.*, 
                   u1.first_name as creator_first_name, u1.last_name as creator_last_name,
                   u2.first_name as assigned_first_name, u2.last_name as assigned_last_name,
                   ts.status as submission_status, ts.submitted_at, ts.review_notes,
                   t.completion_notes
                   FROM tasks t
                   LEFT JOIN users u1 ON t.created_by = u1.id
                   LEFT JOIN users u2 ON t.assigned_to = u2.id
                   LEFT JOIN task_submissions ts ON t.id = ts.task_id
                   ORDER BY t.status ASC, t.due_date ASC";
} else {
    // Fetch tasks assigned to the user
    $tasks_query = "SELECT t.*, 
                   u1.first_name as creator_first_name, u1.last_name as creator_last_name,
                   (SELECT MAX(ts2.id) FROM task_submissions ts2 WHERE ts2.task_id = t.id) as latest_submission_id
                   FROM tasks t
                   LEFT JOIN users u1 ON t.created_by = u1.id
                   WHERE t.assigned_to = ?
                   ORDER BY t.status ASC, t.due_date ASC";
}

$tasks_stmt = $conn->prepare($tasks_query);
if (!$tasks_stmt) {
    die("Error preparing tasks query: " . $conn->error);
}

if ($role !== 'admin') {
    $tasks_stmt->bind_param("i", $user_id);
}

$tasks_stmt->execute();
$tasks_result = $tasks_stmt->get_result();

// Add error checking for tasks query
if (!$tasks_result) {
    die("Error fetching tasks: " . $conn->error);
}

$all_tasks = [];
while ($task = $tasks_result->fetch_assoc()) {
    $all_tasks[] = $task;
}

// Get task counts for the calendar
// Modified SQL query to include overdue status
$task_counts_query = "SELECT 
    DATE(due_date) as date,
    CASE 
        WHEN status = 'pending' AND due_date < NOW() THEN 'overdue'
        ELSE status
    END as calculated_status,
    COUNT(*) as count
FROM tasks
WHERE created_by = ? OR assigned_to = ?
GROUP BY date, calculated_status
ORDER BY date ASC";

$task_counts_stmt = $conn->prepare($task_counts_query);
$task_counts_stmt->bind_param("ii", $user_id, $user_id);
$task_counts_stmt->execute();
$task_counts_result = $task_counts_stmt->get_result();

// Get calendar data
$calendar_data = [];
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

// Get tasks for calendar
$calendar_query = "SELECT t.*, 
                  u.first_name as creator_first_name, u.last_name as creator_last_name
                  FROM tasks t
                  LEFT JOIN users u ON t.created_by = u.id
                  WHERE t.assigned_to = ? AND DATE_FORMAT(t.due_date, '%Y-%m') = ?
                  ORDER BY t.due_date ASC";
$calendar_stmt = $conn->prepare($calendar_query);
$calendar_stmt->bind_param("is", $user_id, $current_month);
$calendar_stmt->execute();
$calendar_result = $calendar_stmt->get_result();

$calendar_tasks = [];
while ($task = $calendar_result->fetch_assoc()) {
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
}

// Get upcoming tasks (next 5 pending tasks)
$upcoming_query = "SELECT t.*, 
                  u.first_name as creator_first_name, u.last_name as creator_last_name
                  FROM tasks t
                  LEFT JOIN users u ON t.created_by = u.id
                  WHERE t.assigned_to = ? AND t.status = 'pending'
                  ORDER BY t.due_date ASC
                  LIMIT 5";
$upcoming_stmt = $conn->prepare($upcoming_query);
$upcoming_stmt->bind_param("i", $user_id);
$upcoming_stmt->execute();
$upcoming_result = $upcoming_stmt->get_result();

$upcoming_tasks = [];
while ($task = $upcoming_result->fetch_assoc()) {
    $upcoming_tasks[] = $task;
}

// Prepare task counts for calendar
$calendar_data = [];
while ($row = $task_counts_result->fetch_assoc()) {
    $date = $row['date'];
    $status = $row['calculated_status'];
    $count = $row['count'];
    
    if (!isset($calendar_data[$date])) {
        $calendar_data[$date] = [
            'pending' => 0,
            'overdue' => 0,
            'completed' => 0
        ];
    }
    
    $calendar_data[$date][$status] = $count;
}

$completed_tasks_query = "SELECT t.*, 
    u1.first_name AS creator_fname, 
    u2.first_name AS assignee_fname
    FROM tasks t
    JOIN users u1 ON t.created_by = u1.id
    JOIN users u2 ON t.assigned_to = u2.id
    WHERE t.status = 'completed'
    ORDER BY t.updated_at DESC";

$completed_tasks = $conn->query($completed_tasks_query);
// Get unread notifications count
$notifications_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND `read` = 0";
$notif_stmt = $conn->prepare($notifications_query);
$notif_stmt->bind_param("i", $user_id);
$notif_stmt->execute();
$notifications_count = $notif_stmt->get_result()->fetch_assoc()['count'];
$notif_stmt->close();

// Quote handling with error checking
$quote = "Success is built one task at a time—stay focused, stay consistent, and make progress daily.";

if ($role === 'admin' && isset($_POST['quote'])) {
    $new_quote = $conn->real_escape_string($_POST['quote']);
    
    // Debugging: Check if the form is submitted
    error_log("Form submitted with quote: " . $new_quote);
    
    // Check if the settings table exists and has the quote
    $check_query = "SELECT value FROM settings WHERE name='motd_quote'";
    $check_result = $conn->query($check_query);
    
    if ($check_result && $check_result->num_rows > 0) {
        // Update existing quote
        $update_query = "UPDATE settings SET value='$new_quote' WHERE name='motd_quote'";
    } else {
        // Insert new quote
        $update_query = "INSERT INTO settings (name, value) VALUES ('motd_quote', '$new_quote')";
    }
    
    if ($conn->query($update_query)) {
        $_SESSION['success'] = "Quote updated successfully!";
        error_log("Quote updated successfully.");
    } else {
        $_SESSION['error'] = "Error updating quote: " . $conn->error;
        error_log("Error updating quote: " . $conn->error);
    }
    
    // Force refresh
    header("Location: ".$_SERVER['REQUEST_URI']);
    exit();
}

// Get current quote with error handling
$result = $conn->query("SELECT value FROM settings WHERE name='motd_quote'");
if ($result && $result->num_rows > 0) {
    $quote = $result->fetch_assoc()['value'];
} else {
    error_log("Failed to fetch quote: " . $conn->error);
}

$user_query = "SELECT id FROM users WHERE id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    die("Error: User does not exist");
}

// Include task notifications after database connection is established
require_once __DIR__ . '/task_notifications.php';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="stylesheet" href="dashboard1.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <style>
        /* General Styles */
        :root {
            --primary-color: #4a90e2;
            --secondary-color: #2c3e50;
            --success-color: #2ecc71;
            --warning-color: #f1c40f;
            --danger-color: #e74c3c;
            --light-color: #f5f5f5;
            --dark-color: #333;
            --border-color: #ddd;
            --text-color: #333;
            --text-muted: #777;
            --shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f8f9fa;
            color: var(--text-color);
            margin: 0;
            padding: 0;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Dashboard Header Styles */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background-color: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: var(--shadow);
        }

        .user-welcome {
            display: flex;
            align-items: center;
        }

        .user-avatar {
            width: 60px;
            height: 60px;
            background-color: var(--primary-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }

        .user-avatar i {
            font-size: 30px;
            color: white;
        }

        .welcome-text h1 {
            margin: 0;
            font-size: 24px;
            color: var(--dark-color);
        }

        .welcome-text p {
            margin: 5px 0 0;
            color: var(--text-muted);
            font-size: 14px;
        }

        .task-stats {
            display: flex;
            gap: 15px;
        }

        .stat-card {
            background-color: white;
            border-radius: 8px;
            padding: 15px;
            min-width: 120px;
            text-align: center;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card i {
            font-size: 24px;
            margin-bottom: 10px;
        }

        .stat-card.pending i {
            color: var(--warning-color);
        }

        .stat-card.completed i {
            color: var(--success-color);
        }

        .stat-card.overdue i {
            color: var(--danger-color);
        }

        .stat-number {
            font-size: 28px;
            font-weight: bold;
            margin: 5px 0;
        }

        .stat-label {
            font-size: 14px;
            color: var(--text-muted);
        }

        /* Tasks Container Styles */
        .tasks-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .task-section {
            background-color: white;
            border-radius: 10px;
            box-shadow: var(--shadow);
            overflow: hidden;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .section-header {
            background-color: var(--light-color);
            padding: 15px 20px;
            display: flex;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
        }

        .section-header i {
            font-size: 18px;
            margin-right: 10px;
        }

        .section-header h2 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }

        .task-list {
            padding: 15px;
            flex-grow: 1;
            overflow-y: auto;
            max-height: 500px;
        }

        .task-item {
            background-color: white;
            border-radius: 8px;
            margin-bottom: 15px;
            padding: 15px;
            border-left: 3px solid var(--primary-color);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .task-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .task-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .task-title {
            font-weight: 600;
            font-size: 16px;
            color: var(--dark-color);
        }

        .task-status {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-pending {
            background-color: rgba(241, 196, 15, 0.2);
            color: #9a7d0a;
        }

        .status-completed {
            background-color: rgba(46, 204, 113, 0.2);
            color: #1d8348;
        }

        .status-overdue {
            background-color: rgba(231, 76, 60, 0.2);
            color: #922b21;
        }

        .task-meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 13px;
            color: var(--text-muted);
        }

        .task-meta span {
            display: flex;
            align-items: center;
        }

        .task-meta i {
            margin-right: 5px;
        }

        .task-description {
            font-size: 14px;
            color: var(--text-color);
            margin-bottom: 15px;
            line-height: 1.5;
        }

        .task-actions {
            display: flex;
            justify-content: flex-end;
        }

        .btn-submit {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            transition: background-color 0.2s;
        }

        .btn-submit:hover {
            background-color: #3a7bc8;
        }

        .no-tasks-message {
            text-align: center;
            color: var(--text-muted);
            padding: 20px;
            font-style: italic;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .dashboard-header {
                flex-direction: column;
            }
            
            .user-welcome {
                margin-bottom: 20px;
            }
            
            .task-stats {
                width: 100%;
                justify-content: space-between;
            }
            
            .tasks-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="dashboard-container">

    <div class="sidebar">
        <div class="quote-section">
            <?php if ($role === 'admin'): ?>
                <div class="quote-header">
                    <button class="edit-quote-btn" onclick="toggleQuoteEdit()">
                        <i class="fas fa-edit"></i>
                    </button>
                </div>
            <?php endif; ?>
            <form id="quoteForm" method="POST" style="display: none;">
                <textarea name="quote" rows="3"><?php echo htmlspecialchars($quote); ?></textarea>
                <button type="submit" class="save-quote-btn">Save</button>
            </form>
            <p class="quote-content" id="quoteDisplay"><?php echo htmlspecialchars($quote); ?></p>
        </div>
        <h2>Menu</h2>
        <?php if ($role === 'admin'): ?>
            <a href="manage_staff.php" class="action-button">
                <i class="fas fa-users"></i> Manage Staff
            </a>
            <a href="manage_tasks.php" class="action-button">
                <i class="fas fa-tasks"></i> Manage Tasks
            </a>
        <?php else: ?>
            <a href="staff_tasks.php" class="action-button">
                <i class="fas fa-tasks"></i> My Tasks
            </a>
        <?php endif; ?>
        <a href="inbox.php" class="action-button">
            <i class="fas fa-envelope"></i> Inbox
        </a>
        <a href="logout.php" class="action-button">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <h2>Welcome, <?php echo htmlspecialchars($username); ?>!</h2>
            <div class="icons">
                <i class="fas fa-bell" onclick="showNotification()">
                    <?php if ($notifications_count > 0): ?>
                        <span class="notification-badge"><?php echo $notifications_count; ?></span>
                    <?php endif; ?>
                </i>
                <i class="fas fa-user" onclick="goToSettings()"></i>
            </div>
        </div>

        <?php if ($role === 'admin'): ?>
            <div id="calendar"></div>
        <?php else: ?>
            <div class="tasks-section">
                <div class="dashboard-header">
                    <div class="user-welcome">
                        <div class="user-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="welcome-text">
                            <h1>Welcome, <?php echo htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']); ?>!</h1>
                            <p>Here's an overview of your tasks</p>
                        </div>
                    </div>

                    <div class="task-stats">
                        <div class="stat-card pending">
                            <i class="fas fa-tasks"></i>
                            <div class="stat-number"><?php 
                                $pending_count = 0;
                                $completed_count = 0;
                                $overdue_count = 0;
                                
                                foreach ($all_tasks as $task) {
                                    if ($task['status'] === 'completed') {
                                        $completed_count++;
                                    } else if ($task['status'] === 'pending') {
                                        if (strtotime($task['due_date']) < time()) {
                                            $overdue_count++;
                                        } else {
                                            $pending_count++;
                                        }
                                    }
                                }
                                
                                echo $pending_count; 
                            ?></div>
                            <div class="stat-label">Pending Tasks</div>
                        </div>
                        <div class="stat-card completed">
                            <i class="fas fa-check-circle"></i>
                            <div class="stat-number"><?php echo $completed_count; ?></div>
                            <div class="stat-label">Completed Tasks</div>
                        </div>
                        <div class="stat-card overdue">
                            <i class="fas fa-exclamation-circle"></i>
                            <div class="stat-number"><?php echo $overdue_count; ?></div>
                            <div class="stat-label">Overdue Tasks</div>
                        </div>
                    </div>
                </div>

                <div class="tasks-container">
                    <!-- Pending Tasks Section -->
                    <div class="task-section">
                        <div class="section-header">
                            <i class="fas fa-hourglass-half"></i>
                            <h2>Pending Tasks</h2>
                        </div>
                        <div class="task-list">
                            <?php 
                            $pending_found = false;
                            foreach ($all_tasks as $task): 
                                if ($task['status'] === 'pending' && strtotime($task['due_date']) >= time()):
                                    $pending_found = true;
                            ?>
                                <div class="task-item" data-id="<?php echo $task['id']; ?>">
                                    <div class="task-header" onclick="window.location.href='view_task.php?id=<?php echo $task['id']; ?>';" style="cursor: pointer;">
                                        <div class="task-title"><?php echo htmlspecialchars($task['title']); ?></div>
                                        <span class="task-status status-pending">Pending</span>
                                    </div>
                                    <div class="task-meta">
                                        <span><i class="fas fa-calendar"></i> Due: <?php echo date('M j, Y', strtotime($task['due_date'])); ?></span>
                                        <span><i class="fas fa-user"></i> From: <?php echo isset($task['creator_first_name']) ? htmlspecialchars($task['creator_first_name'] . ' ' . $task['creator_last_name']) : 'Admin'; ?></span>
                                    </div>
                                    <div class="task-description">
                                        <?php echo htmlspecialchars(substr($task['description'], 0, 100)) . (strlen($task['description']) > 100 ? '...' : ''); ?>
                                    </div>
                                    <div class="task-actions">
                                        <button class="btn-submit" onclick="window.location.href='view_task.php?id=<?php echo $task['id']; ?>';">Submit Task</button>
                                    </div>
                                </div>
                            <?php 
                                endif;
                            endforeach; 
                            
                            if (!$pending_found):
                            ?>
                                <p class="no-tasks-message">No pending tasks!</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Overdue Tasks Section -->
                    <div class="task-section">
                        <div class="section-header">
                            <i class="fas fa-exclamation-circle"></i>
                            <h2>Overdue Tasks</h2>
                        </div>
                        <div class="task-list">
                            <?php 
                            $overdue_found = false;
                            foreach ($all_tasks as $task): 
                                if ($task['status'] === 'pending' && strtotime($task['due_date']) < time()):
                                    $overdue_found = true;
                            ?>
                                <div class="task-item" data-id="<?php echo $task['id']; ?>">
                                    <div class="task-header" onclick="window.location.href='view_task.php?id=<?php echo $task['id']; ?>';" style="cursor: pointer;">
                                        <div class="task-title"><?php echo htmlspecialchars($task['title']); ?></div>
                                        <span class="task-status status-overdue">Overdue</span>
                                    </div>
                                    <div class="task-meta">
                                        <span><i class="fas fa-calendar"></i> Due: <?php echo date('M j, Y', strtotime($task['due_date'])); ?></span>
                                        <span><i class="fas fa-user"></i> From: <?php echo isset($task['creator_first_name']) ? htmlspecialchars($task['creator_first_name'] . ' ' . $task['creator_last_name']) : 'Admin'; ?></span>
                                    </div>
                                    <div class="task-description">
                                        <?php echo htmlspecialchars(substr($task['description'], 0, 100)) . (strlen($task['description']) > 100 ? '...' : ''); ?>
                                    </div>
                                    <div class="task-actions">
                                        <button class="btn-submit" onclick="window.location.href='view_task.php?id=<?php echo $task['id']; ?>';">Submit Task</button>
                                    </div>
                                </div>
                            <?php 
                                endif;
                            endforeach; 
                            
                            if (!$overdue_found):
                            ?>
                                <p class="no-tasks-message">No overdue tasks!</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Completed Tasks Section -->
                    <div class="task-section">
                        <div class="section-header">
                            <i class="fas fa-check-circle"></i>
                            <h2>Completed Tasks</h2>
                        </div>
                        <div class="task-list">
                            <?php 
                            $completed_found = false;
                            foreach ($all_tasks as $task): 
                                if ($task['status'] === 'completed'):
                                    $completed_found = true;
                            ?>
                                <div class="task-item" data-id="<?php echo $task['id']; ?>">
                                    <div class="task-header" onclick="window.location.href='view_task.php?id=<?php echo $task['id']; ?>';" style="cursor: pointer;">
                                        <div class="task-title"><?php echo htmlspecialchars($task['title']); ?></div>
                                        <span class="task-status status-completed">Completed</span>
                                    </div>
                                    <div class="task-meta">
                                        <span><i class="fas fa-calendar"></i> Due: <?php echo date('M j, Y', strtotime($task['due_date'])); ?></span>
                                        <span><i class="fas fa-user"></i> From: <?php echo isset($task['creator_first_name']) ? htmlspecialchars($task['creator_first_name'] . ' ' . $task['creator_last_name']) : 'Admin'; ?></span>
                                    </div>
                                    <div class="task-description">
                                        <?php echo htmlspecialchars(substr($task['description'], 0, 100)) . (strlen($task['description']) > 100 ? '...' : ''); ?>
                                    </div>
                                </div>
                            <?php 
                                endif;
                            endforeach; 
                            
                            if (!$completed_found):
                            ?>
                                <p class="no-tasks-message">No completed tasks!</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Task Details Modal -->
<div id="taskModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Task Details</h2>
            <span class="close" onclick="closeTaskModal()">&times;</span>
        </div>
        <div class="modal-body" id="taskModalContent">
            <!-- Task details will be loaded here -->
        </div>
    </div>
</div>

<!-- Task Submission Modal -->
<div id="taskSubmissionModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Submit Task</h2>
            <span class="close" onclick="closeSubmissionModal()">&times;</span>
        </div>
        <div class="modal-body">
            <form id="taskSubmissionForm" action="submit_task.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="task_id" id="submission_task_id">
                
                <div class="task-details">
                    <h3 id="submission_task_title"></h3>
                    <p id="submission_task_description"></p>
                    <p id="submission_task_due_date"></p>
                </div>

                <div class="form-group">
                    <label for="comments">Completion Notes:</label>
                    <textarea name="comments" id="comments" rows="4" required></textarea>
                </div>

                <div class="form-group">
                    <label for="attachments">Attachments (Optional):</label>
                    <input type="file" name="attachments[]" id="attachments" multiple>
                    <small>Allowed files: PDF, Word, Images (max 5MB each)</small>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Submit Task</button>
                    <button type="button" class="btn btn-secondary" onclick="closeSubmissionModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Notifications Modal -->
<div id="notificationModal" class="modal notification-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-bell"></i> Notifications</h3>
            <span class="close-modal" onclick="closeNotificationModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div id="notificationsList"></div>
        </div>
    </div>
</div>

<!-- Calendar Task Modal -->
<div id="calendarTaskModal" class="modal">
    <div class="modal-content calendar-task-modal">
        <div class="modal-header">
            <h3>Tasks for <span id="modalDate"></span></h3>
            <span class="close" onclick="closeCalendarModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div class="task-tabs">
                <div class="task-tab">
                    <h4>Pending Tasks (<span id="pendingCount">0</span>)</h4>
                    <div id="pendingTasks" class="task-list"></div>
                </div>
                <div class="task-tab">
                    <h4>Completed Tasks (<span id="completedCount">0</span>)</h4>
                    <div id="completedTasks" class="task-list"></div>
                </div>
                <div class="task-tab">
                    <h4>Overdue Tasks (<span id="overdueCount">0</span>)</h4>
                    <div id="overdueTasks" class="task-list"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Replace the existing calendar initialization with this
document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('calendar');
    if (calendarEl) {
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay'
            },
            dayCellDidMount: function(info) {
                const date = info.date.toISOString().split('T')[0];
                
                fetch(`get_task_counts.php?date=${date}`)
                    .then(response => response.json())
                    .then(data => {
                        // Only show indicators if there are actual tasks
                        if (data.total > 0) {
                            const taskCount = document.createElement('div');
                            taskCount.className = 'task-count';
                            
                            // Create the task status display
                            let statusHtml = '';
                            if (data.pending > 0) statusHtml += `<span class="status-item pending">${data.pending}P</span>`;
                            if (data.completed > 0) statusHtml += `<span class="status-item completed">${data.completed}C</span>`;
                            if (data.overdue > 0) statusHtml += `<span class="status-item overdue">${data.overdue}O</span>`;
                            
                            taskCount.innerHTML = `<div class="task-status-row">${statusHtml}</div>`;
                            info.el.appendChild(taskCount);
                            info.el.classList.add('has-tasks');
                        }
                    })
                    .catch(error => console.error('Error fetching task counts:', error));
            },
            dateClick: function(info) {
                showCalendarTaskModal(info.dateStr);
            }
        });
        calendar.render();
        
        // Add the CSS for task indicators
        const style = document.createElement('style');
        style.textContent = `
            .task-count {
                font-size: 10px;
                margin-top: 4px;
                text-align: center;
            }
            
            .task-status-row {
                display: flex;
                gap: 4px;
                justify-content: center;
                align-items: center;
            }
            
            .status-item {
                padding: 1px 3px;
                border-radius: 3px;
                font-weight: bold;
            }
            
            .status-item.pending {
                background-color: #fff3cd;
                color: #856404;
            }
            
            .status-item.completed {
                background-color: #d4edda;
                color: #155724;
            }
            
            .status-item.overdue {
                background-color: #f8d7da;
                color: #721c24;
            }
            
            .fc-daygrid-day.has-tasks:hover {
                background-color: rgba(0, 0, 0, 0.05);
            }
        `;
        document.head.appendChild(style);
    }
});
function showTaskDetails(date) {
    const modal = document.getElementById('taskModal');
    const now = new Date();
    
    // Fetch tasks and notifications
    Promise.all([
        fetch(`get_tasks.php?date=${date}`).then(response => response.json()),
        fetch('task_notifications.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=get_notifications'
        }).then(response => response.json())
    ])
    .then(([tasks, notifications]) => {
        // Clear existing content and counts
        ['pendingTasks', 'completedTasks', 'missingTasks'].forEach(id => {
            document.getElementById(id).innerHTML = '';
        });
        
        let pendingCount = 0, completedCount = 0, missingCount = 0;

        // Display tasks
        tasks.forEach(task => {
            const taskDueDate = new Date(task.due_date);
            const isOverdue = task.status === 'pending' && taskDueDate < now;
            
            const taskHtml = `
                <div class="task-item ${isOverdue ? 'missing-task' : ''}">
                    <h5>${task.title}</h5>
                    
                    <div class="task-meta">
                        <p>Due: ${taskDueDate.toLocaleString()}</p>
                        <p>Status: <span class="status-badge status-${task.status}">${task.status}</span></p>
                    </div>
                    
                    ${task.description ? `<p>${task.description}</p>` : ''}
                    <div class="task-actions">
                        <a href="edit_task.php?id=${task.id}" class="btn btn-edit">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                    </div>
                </div>
            `;

            if (task.status === 'completed') {
                document.getElementById('completedTasks').innerHTML += taskHtml;
                completedCount++;
            } else if (isOverdue) {
                document.getElementById('missingTasks').innerHTML += taskHtml;
                missingCount++;
            } else {
                document.getElementById('pendingTasks').innerHTML += taskHtml;
                pendingCount++;
            }
        });

        // Update counts
        document.getElementById('pendingCount').textContent = pendingCount;
        document.getElementById('completedCount').textContent = completedCount;
        document.getElementById('missingCount').textContent = missingCount;

        // Display notifications
        const notificationsList = document.getElementById('taskNotificationsList');
        notificationsList.innerHTML = '';
        
        if (notifications.length === 0) {
            notificationsList.innerHTML = '<p class="no-notifications">No notifications for this date</p>';
        } else {
            notifications.forEach(notification => {
                const icon = getNotificationIcon(notification);
                const formattedMessage = formatNotificationMessage(notification);
                const timeAgo = formatTimestamp(notification.created_at);
                
                // Add task-specific information if it's a task notification
                const taskInfo = '';
                if (notification.task_id && notification.task_title) {
                    taskInfo = `
                        <div class="task-info">
                            <div class="task-header">
                                <h4>${notification.task_title}</h4>
                                ${notification.task_description ? 
                                    `<p class="task-desc">${notification.task_description}</p>` : ''}
                            </div>
                            ${notification.task_due_date ? 
                                `<div class="task-footer">
                                    <span class="due-date">
                                        <i class="fas fa-calendar-alt"></i> 
                                        Due: ${formatDueDate(notification.task_due_date)}
                                    </span>
                                </div>` : ''}
                        </div>
                    `;
                }
                
                const notificationHtml = `
                    <div class="notification-item ${notification.read ? 'read' : 'unread'}" 
                         onclick="handleNotification(${notification.id}, '${notification.type}', ${notification.task_id || 'null'})">
                        <div class="notification-avatar">
                            <i class="${icon}"></i>
                        </div>
                        <div class="notification-content">
                            <div class="notification-header">
                                <span class="notification-type">${formatNotificationType(notification.type)}</span>
                                ${notification.sender_name ? 
                                    `<span class="notification-sender">From: ${notification.sender_name}</span>` : 
                                    ''}
                            </div>
                            <div class="message">${formattedMessage}</div>
                            ${taskInfo}
                            <div class="notification-footer">
                                <span class="notification-time">
                                    <i class="far fa-clock"></i>
                                    ${timeAgo}
                                </span>
                            </div>
                        </div>
                        ${!notification.read ? '<span class="unread-dot"></span>' : ''}
                    </div>
                `;
                notificationsList.innerHTML += notificationHtml;
            });
        }
        
        modal.style.display = 'block';
    });
}

function markNotificationAsRead(notificationId) {
    fetch('mark_notification_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ notification_id: notificationId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update UI to show notification as read
            const notification = document.querySelector(`.notification-item[onclick*="${notificationId}"]`);
            if (notification) {
                notification.classList.remove('unread');
                notification.classList.add('read');
                notification.querySelector('.unread-dot')?.remove();
            }
            
            // Update notification badge count
            updateNotificationCount();
            
            // Handle different notification types
            if (taskId) {
                // Close the notification modal
                document.getElementById('notificationModal').style.display = 'none';
                
                // Show task details
                showTaskModal(taskId);
            }
        }
    })
    .catch(error => {
        console.error('Error marking notification as read:', error);
    });
}

// Update notification count
function updateNotificationCount() {
    fetch('get_notifications.php')
        .then(response => response.json())
        .then(data => {
            var unreadCount = data.filter(n => !n.is_read).length;
            var badge = document.querySelector('.notification-badge');
            if (unreadCount > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'notification-badge';
                    document.querySelector('.fa-bell').appendChild(badge);
                }
                badge.textContent = unreadCount;
            } else if (badge) {
                badge.remove();
            }
        });
}

// Update notification count every minute
setInterval(updateNotificationCount, 60000);

function goToSettings() {
    window.location.href = "user_management.php";
}

function showTaskDetails(date) {
    const modal = document.getElementById('taskModal');
    const now = new Date();
    
    // Fetch tasks and notifications
    Promise.all([
        fetch(`get_tasks.php?date=${date}`).then(response => response.json()),
        fetch('task_notifications.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=get_notifications'
        }).then(response => response.json())
    ])
    .then(([tasks, notifications]) => {
        // Clear existing content and counts
        ['pendingTasks', 'completedTasks', 'missingTasks'].forEach(id => {
            document.getElementById(id).innerHTML = '';
        });
        
        let pendingCount = 0, completedCount = 0, missingCount = 0;

        // Display tasks
        tasks.forEach(task => {
            const taskDueDate = new Date(task.due_date);
            const isOverdue = task.status === 'pending' && taskDueDate < now;
            
            const taskHtml = `
                <div class="task-item ${isOverdue ? 'missing-task' : ''}">
                    <h5>${task.title}</h5>
                    
                    <div class="task-meta">
                        <p>Due: ${taskDueDate.toLocaleString()}</p>
                        <p>Status: <span class="status-badge status-${task.status}">${task.status}</span></p>
                    </div>
                    
                    ${task.description ? `<p>${task.description}</p>` : ''}
                    <div class="task-actions">
                        <a href="edit_task.php?id=${task.id}" class="btn btn-edit">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                    </div>
                </div>
            `;

            if (task.status === 'completed') {
                document.getElementById('completedTasks').innerHTML += taskHtml;
                completedCount++;
            } else if (isOverdue) {
                document.getElementById('missingTasks').innerHTML += taskHtml;
                missingCount++;
            } else {
                document.getElementById('pendingTasks').innerHTML += taskHtml;
                pendingCount++;
            }
        });

        // Update counts
        document.getElementById('pendingCount').textContent = pendingCount;
        document.getElementById('completedCount').textContent = completedCount;
        document.getElementById('missingCount').textContent = missingCount;

        // Display notifications
        const notificationsList = document.getElementById('taskNotificationsList');
        notificationsList.innerHTML = '';
        
        if (notifications.length === 0) {
            notificationsList.innerHTML = '<p class="no-notifications">No notifications for this date</p>';
        } else {
            notifications.forEach(notification => {
                const icon = getNotificationIcon(notification);
                const formattedMessage = formatNotificationMessage(notification);
                const timeAgo = formatTimestamp(notification.created_at);
                
                // Add task-specific information if it's a task notification
                const taskInfo = '';
                if (notification.task_id && notification.task_title) {
                    taskInfo = `
                        <div class="task-info">
                            <div class="task-header">
                                <h4>${notification.task_title}</h4>
                                ${notification.task_description ? 
                                    `<p class="task-desc">${notification.task_description}</p>` : ''}
                            </div>
                            ${notification.task_due_date ? 
                                `<div class="task-footer">
                                    <span class="due-date">
                                        <i class="fas fa-calendar-alt"></i> 
                                        Due: ${formatDueDate(notification.task_due_date)}
                                    </span>
                                </div>` : ''}
                        </div>
                    `;
                }
                
                const notificationHtml = `
                    <div class="notification-item ${notification.read ? 'read' : 'unread'}" 
                         onclick="handleNotification(${notification.id}, '${notification.type}', ${notification.task_id || 'null'})">
                        <div class="notification-avatar">
                            <i class="${icon}"></i>
                        </div>
                        <div class="notification-content">
                            <div class="notification-header">
                                <span class="notification-type">${formatNotificationType(notification.type)}</span>
                                ${notification.sender_name ? 
                                    `<span class="notification-sender">From: ${notification.sender_name}</span>` : 
                                    ''}
                            </div>
                            <div class="message">${formattedMessage}</div>
                            ${taskInfo}
                            <div class="notification-footer">
                                <span class="notification-time">
                                    <i class="far fa-clock"></i>
                                    ${timeAgo}
                                </span>
                            </div>
                        </div>
                        ${!notification.read ? '<span class="unread-dot"></span>' : ''}
                    </div>
                `;
                notificationsList.innerHTML += notificationHtml;
            });
        }
        
        modal.style.display = 'block';
    });
}

function showNotification() {
    var modal = document.getElementById('notificationModal');
    modal.style.display = 'block';
    
    // Fetch notifications
    fetch('get_notifications.php')
        .then(response => response.json())
        .then(data => {
            var notificationsList = document.getElementById('notificationsList');
            notificationsList.innerHTML = '';
            
            if (data.length === 0) {
                notificationsList.innerHTML = `
                    <div class="notification-empty">
                        <i class="fas fa-bell-slash"></i>
                        <p>No notifications yet</p>
                    </div>
                `;
                return;
            }
            
            data.forEach(notification => {
                var icon = getNotificationIcon(notification);
                var formattedMessage = notification.message || formatNotificationMessage(notification);
                var timeAgo = formatTimestamp(notification.created_at);
                
                // Add task-specific information if it's a task notification
                var taskInfo = '';
                if (notification.task_id && notification.task_title) {
                    taskInfo = `
                        <div class="task-info">
                            <div class="task-header">
                                <h4>${notification.task_title}</h4>
                                ${notification.task_description ? 
                                    `<p class="task-desc">${notification.task_description.substring(0, 100)}${notification.task_description.length > 100 ? '...' : ''}</p>` : ''}
                            </div>
                            ${notification.task_due_date ? 
                                `<div class="task-footer">
                                    <span class="due-date">
                                        <i class="fas fa-calendar-alt"></i> 
                                        Due: ${formatDueDate(notification.task_due_date)}
                                    </span>
                                </div>` : ''}
                        </div>
                    `;
                }
                
                var notificationHtml = `
                    <div class="notification-item ${notification.read ? 'read' : 'unread'}" 
                         onclick="handleNotification(${notification.id}, '${notification.type}', ${notification.task_id || 'null'})">
                        <div class="notification-avatar">
                            <i class="${icon}"></i>
                        </div>
                        <div class="notification-content">
                            <div class="notification-header">
                                <span class="notification-type">${formatNotificationType(notification.type)}</span>
                                ${notification.sender_name ? 
                                    `<span class="notification-sender">From: ${notification.sender_name}</span>` : 
                                    ''}
                            </div>
                            <div class="message">${formattedMessage}</div>
                            ${taskInfo}
                            <div class="notification-footer">
                                <span class="notification-time">
                                    <i class="far fa-clock"></i>
                                    ${timeAgo}
                                </span>
                            </div>
                        </div>
                        ${!notification.read ? '<span class="unread-dot"></span>' : ''}
                    </div>
                `;
                notificationsList.innerHTML += notificationHtml;
            });
            
            // Update notification badge
            updateNotificationBadge();
        })
        .catch(error => {
            console.error('Error fetching notifications:', error);
            document.getElementById('notificationsList').innerHTML = `
                <div class="notification-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <p>Failed to load notifications. Please try again.</p>
                </div>
            `;
        });
}

function handleNotification(notificationId, type, taskId) {
    // Mark notification as read
    fetch('task_notifications.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=mark_read&notification_id=${notificationId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update UI to show notification as read
            document.querySelector(`.notification-item[onclick*="${notificationId}"]`).classList.add('read');
            document.querySelector(`.notification-item[onclick*="${notificationId}"] .unread-dot`)?.remove();
            
            // Update notification badge
            updateNotificationBadge();
        }
    })
    .catch(error => console.error('Error marking notification as read:', error));
    
    // If it's a task notification, redirect to the task
    if (taskId) {
        window.location.href = `view_task.php?id=${taskId}`;
    }
}

function updateNotificationBadge() {
    // Count unread notifications
    fetch('get_unread_count.php')
        .then(response => response.json())
        .then(data => {
            const badge = document.querySelector('.notification-badge');
            if (data.count > 0) {
                if (badge) {
                    badge.textContent = data.count;
                } else {
                    const newBadge = document.createElement('span');
                    newBadge.className = 'notification-badge';
                    newBadge.textContent = data.count;
                    document.querySelector('.fas.fa-bell').appendChild(newBadge);
                }
            } else if (badge) {
                badge.remove();
            }
        })
        .catch(error => console.error('Error updating notification badge:', error));
}
function closeNotificationModal() {
    document.getElementById('notificationModal').style.display = 'none';
}

function getNotificationIcon(type) {
    switch(type) {
        case 'task_assigned':
            return 'fas fa-tasks';
        case 'task_completed':
            return 'fas fa-check-circle';
        case 'task_status_change':
            return 'fas fa-sync-alt';
        case 'task_reminder':
            return 'fas fa-clock';
        case 'task_comment':
            return 'fas fa-comment';
        case 'task_updated':
            return 'fas fa-edit';
        default:
            return 'fas fa-bell';
    }
}

function formatNotificationType(type) {
    switch(type) {
        case 'task_assigned':
            return 'Task Assigned';
        case 'task_completed':
            return 'Task Completed';
        case 'task_status_change':
            return 'Status Change';
        case 'task_reminder':
            return 'Reminder';
        case 'task_comment':
            return 'Comment';
        case 'task_updated':
            return 'Task Updated';
        default:
            return 'Notification';
    }
}

function formatNotificationMessage(notification) {
    switch(notification.type) {
        case 'task_assigned':
            return `You have been assigned a new task: "${notification.task_title}"`;
        case 'task_completed':
            return `Task "${notification.task_title}" has been completed`;
        case 'task_status_change':
            return notification.message;
        case 'task_reminder':
            return `Reminder: Task "${notification.task_title}" is due ${formatDueDate(notification.task_due_date)}`;
        case 'task_comment':
            return `New comment on task "${notification.task_title}": ${notification.message}`;
        case 'task_updated':
            return `Task "${notification.task_title}" has been updated`;
        default:
            return notification.message;
    }
}

function formatDueDate(dueDate) {
    const date = new Date(dueDate);
    const now = new Date();
    const tomorrow = new Date(now);
    tomorrow.setDate(tomorrow.getDate() + 1);
    
    if (date.toDateString() === now.toDateString()) {
        return 'today at ' + date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    } else if (date.toDateString() === tomorrow.toDateString()) {
        return 'tomorrow at ' + date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    } else {
        return date.toLocaleDateString('en-US', { 
            month: 'short', 
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit'
        });
    }
}

function formatTimestamp(timestamp) {
    const date = new Date(timestamp);
    const now = new Date();
    const diff = now - date;
    
    // If less than 24 hours ago, show relative time
    if (diff < 24 * 60 * 60 * 1000) {
        if (diff < 60 * 1000) return 'Just now';
        if (diff < 60 * 60 * 1000) return `${Math.floor(diff / (60 * 1000))}m ago`;
        return `${Math.floor(diff / (60 * 60 * 1000))}h ago`;
    }
    
    // If this year, show date without year
    if (date.getFullYear() === now.getFullYear()) {
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }
    
    // Otherwise show full date
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function handleNotification(notificationId, type, taskId) {
    // Mark notification as read
    fetch('mark_notification_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ notification_id: notificationId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update UI to show notification as read
            const notification = document.querySelector(`.notification-item[onclick*="${notificationId}"]`);
            if (notification) {
                notification.classList.remove('unread');
                notification.classList.add('read');
                notification.querySelector('.unread-dot')?.remove();
            }
            
            // Update notification badge count
            updateNotificationCount();
            
            // Handle different notification types
            if (taskId) {
                // Close the notification modal
                document.getElementById('notificationModal').style.display = 'none';
                
                // Show task details
                showTaskModal(taskId);
            }
        }
    })
    .catch(error => {
        console.error('Error marking notification as read:', error);
    });
}

// Update notification count
function updateNotificationCount() {
    fetch('get_notifications.php')
        .then(response => response.json())
        .then(data => {
            var unreadCount = data.filter(n => !n.is_read).length;
            var badge = document.querySelector('.notification-badge');
            if (unreadCount > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'notification-badge';
                    document.querySelector('.fa-bell').appendChild(badge);
                }
                badge.textContent = unreadCount;
            } else if (badge) {
                badge.remove();
            }
        });
}

// Update notification count every minute
setInterval(updateNotificationCount, 60000);

function goToSettings() {
    window.location.href = "user_management.php";
}

function showTaskModal(title, description, dueDate, taskId) {
    const modal = document.getElementById('taskModal');
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalDescription').textContent = description;
    document.getElementById('modalDueDate').textContent = dueDate;
    document.getElementById('modalTaskId').value = taskId;
    modal.style.display = 'block';
}

// Close modal
document.querySelector('.close').onclick = function() {
    document.getElementById('taskModal').style.display = 'none';
}

// Handle form submission
document.getElementById('taskSubmissionForm').onsubmit = async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    try {
        const response = await fetch('submit_task.php', {
            method: 'POST',
            body: formData
        });
        
        if (response.ok) {
            alert('Task submitted successfully!');
            location.reload();
        } else {
            alert('Error submitting task');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('An error occurred while submitting the task');
    }
};

function toggleQuoteEdit() {
    const form = document.getElementById('quoteForm');
    const display = document.getElementById('quoteDisplay');
    
    if (form.style.display === 'none' || form.style.display === '') {
        form.style.display = 'block';
        display.style.display = 'none';
        form.querySelector('textarea').focus();
    } else {
        form.style.display = 'none';
        display.style.display = 'block';
    }
}

document.getElementById('quoteForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    
    fetch('update_quote.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('quoteDisplay').textContent = formData.get('quote');
            toggleQuoteEdit();
        } else {
            alert('Error updating quote: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while updating the quote');
    });
});

document.addEventListener('DOMContentLoaded', function() {
document.getElementById('quoteForm').onsubmit = function(e) {
    e.preventDefault(); // Prevent default form submission
    const formData = new FormData(this);
    
    fetch('update_quote.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Quote updated successfully!');
            location.reload(); // Refresh the page to show the updated quote
        } else {
            alert('Error updating quote: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while updating the quote');
    });
};
});

// Add this after the existing script
document.addEventListener('DOMContentLoaded', function() {
    // Close notification modal when clicking the X
    const closeNotificationModal = document.querySelector('#notificationModal .close-modal');
    if (closeNotificationModal) {
        closeNotificationModal.onclick = function() {
            document.getElementById('notificationModal').style.display = 'none';
        }
    }

    // Close notification modal when clicking outside
    window.onclick = function(event) {
        const notificationModal = document.getElementById('notificationModal');
        if (event.target == notificationModal) {
            notificationModal.style.display = 'none';
        }
    }
});

function showSubmissionModal(taskData) {
    const modal = document.getElementById('taskSubmissionModal');
    document.getElementById('submission_task_id').value = taskData.id;
    document.getElementById('submission_task_title').textContent = taskData.title;
    document.getElementById('submission_task_description').textContent = taskData.description;
    document.getElementById('submission_task_due_date').textContent = 'Due: ' + new Date(taskData.due_date).toLocaleDateString();
    modal.style.display = 'block';
}

function closeSubmissionModal() {
    const modal = document.getElementById('taskSubmissionModal');
    modal.style.display = 'none';
    document.getElementById('taskSubmissionForm').reset();
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('taskSubmissionModal');
    if (event.target == modal) {
        closeSubmissionModal();
    }
}

// Handle form submission
document.getElementById('taskSubmissionForm').onsubmit = function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('submit_task.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Task submitted successfully!');
            closeSubmissionModal();
            location.reload(); // Refresh to show updated task status
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while submitting the task.');
    });
};

function showTaskModal(taskId) {
    const modal = document.getElementById('taskModal');
    const modalContent = document.getElementById('taskModalContent');
    
    // Fetch task details using AJAX
    fetch(`get_task_details.php?task_id=${taskId}`)
        .then(response => response.text())
        .then(data => {
            modalContent.innerHTML = data;
            modal.style.display = 'block';
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading task details');
        });
}

function closeTaskModal() {
    const modal = document.getElementById('taskModal');
    modal.style.display = 'none';
}

// Close modals when clicking outside
window.onclick = function(event) {
    const taskModal = document.getElementById('taskModal');
    const submissionModal = document.getElementById('taskSubmissionModal');
    
    if (event.target == taskModal) {
        closeTaskModal();
    }
    if (event.target == submissionModal) {
        closeSubmissionModal();
    }
}

// Calendar task modal functions
function showCalendarTaskModal(date) {
    const modal = document.getElementById('calendarTaskModal');
    const modalDate = document.getElementById('modalDate');
    
    // Format the date for display
    const displayDate = new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
    
    modalDate.textContent = displayDate;
    
    // Show loading state
    document.getElementById('pendingTasks').innerHTML = '<div class="loading">Loading tasks...</div>';
    document.getElementById('completedTasks').innerHTML = '<div class="loading">Loading tasks...</div>';
    document.getElementById('overdueTasks').innerHTML = '<div class="loading">Loading tasks...</div>';
    
    console.log('Fetching tasks for date:', date);
    
    // Fetch tasks for this date
    fetch(`get_calendar_tasks.php?date=${date}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            console.log('Calendar tasks data:', data); // Debug log
            
            // Update counts
            document.getElementById('pendingCount').textContent = data.pending ? data.pending.length : 0;
            document.getElementById('completedCount').textContent = data.completed ? data.completed.length : 0;
            document.getElementById('overdueCount').textContent = data.overdue ? data.overdue.length : 0;
            
            // Display pending tasks
            const pendingContainer = document.getElementById('pendingTasks');
            if (data.pending && data.pending.length > 0) {
                pendingContainer.innerHTML = '';
                data.pending.forEach(task => {
                    const dueDate = new Date(task.due_date);
                    pendingContainer.innerHTML += `
                        <div class="task-item">
                            <h4>${escapeHtml(task.title)}</h4>
                            
                            <div class="task-details">
                                <p>Due: ${dueDate.toLocaleDateString()}, 
                                   ${dueDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</p>
                                
                                <p>Status: <span class="status-badge pending">pending</span></p>
                            </div>
                            
                            <p>${escapeHtml(task.description)}</p>
                            
                            <button class="btn btn-primary btn-sm edit-btn" onclick="showTaskModal(${task.id})">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                        </div>
                    `;
                });
            } else {
                pendingContainer.innerHTML = '<p class="no-tasks">No pending tasks</p>';
            }
            
            // Display completed tasks
            const completedContainer = document.getElementById('completedTasks');
            if (data.completed && data.completed.length > 0) {
                completedContainer.innerHTML = '';
                data.completed.forEach(task => {
                    const completedDate = task.completed_at ? new Date(task.completed_at) : new Date();
                    completedContainer.innerHTML += `
                        <div class="task-item">
                            <h4>${escapeHtml(task.title)}</h4>
                            
                            <div class="task-details">
                                <p>Completed: ${completedDate.toLocaleDateString()}, 
                                   ${completedDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</p>
                                
                                <p>Status: <span class="status-badge completed">completed</span></p>
                            </div>
                            
                            <p>${escapeHtml(task.description)}</p>
                            
                            <button class="btn btn-primary btn-sm edit-btn" onclick="showTaskModal(${task.id})">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                        </div>
                    `;
                });
            } else {
                completedContainer.innerHTML = '<p class="no-tasks">No completed tasks</p>';
            }
            
            // Display overdue tasks
            const overdueContainer = document.getElementById('overdueTasks');
            if (data.overdue && data.overdue.length > 0) {
                overdueContainer.innerHTML = '';
                data.overdue.forEach(task => {
                    const dueDate = new Date(task.due_date);
                    overdueContainer.innerHTML += `
                        <div class="task-item">
                            <h4>${escapeHtml(task.title)}</h4>
                            
                            <div class="task-details">
                                <p>Due: ${dueDate.toLocaleDateString()}, 
                                   ${dueDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</p>
                                
                                <p>Status: <span class="status-badge overdue">overdue</span></p>
                            </div>
                            
                            <p>${escapeHtml(task.description)}</p>
                            
                            <button class="btn btn-primary btn-sm edit-btn" onclick="showTaskModal(${task.id})">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                        </div>
                    `;
                });
            } else {
                overdueContainer.innerHTML = '<p class="no-tasks">No overdue tasks</p>';
            }
            
            modal.style.display = 'block';
        })
        .catch(error => {
            console.error('Error loading calendar tasks:', error);
            document.getElementById('pendingTasks').innerHTML = '<p class="error">Error loading tasks</p>';
            document.getElementById('completedTasks').innerHTML = '<p class="error">Error loading tasks</p>';
            document.getElementById('overdueTasks').innerHTML = '<p class="error">Error loading tasks</p>';
            modal.style.display = 'block';
        });
}

function createTaskHtml(task, type) {
    const isCompleted = type === 'completed';
    return `
        <div class="task-item ${type}">
            <h4>${escapeHtml(task.title)}</h4>
            
            <div class="task-details">
                <p>${isCompleted ? 'Completed' : 'Due'}: ${new Date(isCompleted ? task.completed_at : task.due_date).toLocaleString()}</p>
                
                <p>Status: <span class="status-badge ${task.status}">${task.status}</span></p>
            </div>
            
            <p>${escapeHtml(task.description)}</p>
            
            <button class="btn btn-primary btn-sm edit-btn" onclick="showTaskModal(${task.id})">
                <i class="fas fa-edit"></i> Edit
            </button>
        </div>
    `;
}

function escapeHtml(unsafe) {
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// Function to close calendar modal
function closeCalendarModal() {
    const modal = document.getElementById('calendarTaskModal');
    modal.style.display = 'none';
}

// Function to handle task clicks in the calendar modal
function handleTaskClick(taskId) {
    // Close the calendar modal
    closeCalendarModal();
    // Show the task details modal
    showTaskModal(taskId);
}

document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('calendar');
    if (calendarEl) {
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay'
            },
            dateClick: function(info) {
                showCalendarTaskModal(info.dateStr);
            },
            eventClick: function(info) {
                showCalendarTaskModal(info.event.start.toISOString().split('T')[0]);
            }
        });
        calendar.render();
    }
});

function showCalendarTaskModal(date) {
    const modal = document.getElementById('calendarTaskModal');
    const modalDate = document.getElementById('modalDate');
    
    // Format the date for display
    const displayDate = new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
    
    modalDate.textContent = displayDate;
    
    // Show loading state
    document.getElementById('pendingTasks').innerHTML = '<div class="loading">Loading...</div>';
    document.getElementById('completedTasks').innerHTML = '<div class="loading">Loading...</div>';
    document.getElementById('overdueTasks').innerHTML = '<div class="loading">Loading...</div>';
    
    // Fetch tasks for this date
    fetch(`get_calendar_tasks.php?date=${date}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            console.log('Calendar tasks data:', data); // Debug log
            
            // Update counts
            document.getElementById('pendingCount').textContent = data.pending ? data.pending.length : 0;
            document.getElementById('completedCount').textContent = data.completed ? data.completed.length : 0;
            document.getElementById('overdueCount').textContent = data.overdue ? data.overdue.length : 0;
            
            // Always show all sections with appropriate messages
            const pendingContainer = document.getElementById('pendingTasks');
            if (data.pending && data.pending.length > 0) {
                pendingContainer.innerHTML = '';
                data.pending.forEach(task => {
                    console.log('Pending task:', task); // Debug log
                    pendingContainer.innerHTML += `
                        <div class="task-item pending">
                            <h4>${escapeHtml(task.title)}</h4>
                            <p>Assigned to: ${escapeHtml(task.assigned_to_name || 'Unassigned')}</p>
                            <p>Due: ${new Date(task.due_date).toLocaleString()}</p>
                            <span class="priority-badge ${task.priority.toLowerCase()}">${task.priority || 'Medium'}</span>
                        </div>
                    `;
                });
            } else {
                pendingContainer.innerHTML = '<p class="no-tasks">No pending tasks</p>';
            }
            
            const completedContainer = document.getElementById('completedTasks');
            if (data.completed && data.completed.length > 0) {
                completedContainer.innerHTML = '';
                data.completed.forEach(task => {
                    console.log('Completed task:', task); // Debug log
                    completedContainer.innerHTML += `
                        <div class="task-item completed">
                            <h4>${escapeHtml(task.title)}</h4>
                            <p>Completed by: ${escapeHtml(task.assigned_to_name || 'Unknown')}</p>
                            <p>Completed: ${task.completed_at ? new Date(task.completed_at).toLocaleString() : 'Unknown'}</p>
                            <span class="priority-badge ${task.priority.toLowerCase()}">${task.priority || 'Medium'}</span>
                        </div>
                    `;
                });
            } else {
                completedContainer.innerHTML = '<p class="no-tasks">No completed tasks</p>';
            }
            
            const overdueContainer = document.getElementById('overdueTasks');
            if (data.overdue && data.overdue.length > 0) {
                overdueContainer.innerHTML = '';
                data.overdue.forEach(task => {
                    console.log('Overdue task:', task); // Debug log
                    overdueContainer.innerHTML += `
                        <div class="task-item overdue">
                            <h4>${escapeHtml(task.title)}</h4>
                            <p>Assigned to: ${escapeHtml(task.assigned_to_name || 'Unassigned')}</p>
                            <p>Due: ${new Date(task.due_date).toLocaleString()}</p>
                            <span class="priority-badge ${task.priority.toLowerCase()}">${task.priority || 'Medium'}</span>
                        </div>
                    `;
                });
            } else {
                overdueContainer.innerHTML = '<p class="no-tasks">No overdue tasks</p>';
            }
            
            modal.style.display = 'block';
        })
        .catch(error => {
            console.error('Error loading calendar tasks:', error);
            document.getElementById('pendingTasks').innerHTML = '<p class="error">Error loading tasks</p>';
            document.getElementById('completedTasks').innerHTML = '<p class="error">Error loading tasks</p>';
            document.getElementById('overdueTasks').innerHTML = '<p class="error">Error loading tasks</p>';
            modal.style.display = 'block';
        });
}

// Add these styles
const styleSheet = document.createElement("style");
styleSheet.textContent = `
    /* Calendar Styles */
    .calendar-section {
        background-color: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        margin-bottom: 30px;
        overflow: hidden;
        padding: 20px;
    }
    
    .calendar-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .calendar-nav {
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .calendar-nav-btn {
        background-color: #4a90e2;
        color: white;
        border: none;
        border-radius: 4px;
        padding: 8px 15px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 0.9rem;
    }
    
    .calendar-nav-btn:hover {
        background-color: #3a7bc8;
    }
    
    .current-month {
        font-size: 1.2rem;
        font-weight: 500;
    }
    
    .calendar-view-options {
        display: flex;
        gap: 5px;
    }
    
    .view-btn {
        background-color: #f0f0f0;
        color: #333;
        padding: 6px 15px;
        border-radius: 4px;
        font-size: 14px;
        text-decoration: none;
        transition: all 0.2s;
    }
    
    .view-btn.active {
        background-color: #3498db;
        color: white;
    }
    
    .view-btn:hover:not(.active) {
        background-color: #e0e0e0;
    }
    
    .calendar-body {
        padding: 10px;
        overflow-y: auto;
        max-height: 600px;
    }
    
    .calendar-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }
    
    .calendar-table th {
        padding: 10px;
        text-align: center;
        font-weight: 600;
        color: #555;
        border-bottom: 1px solid #eee;
    }
    
    .calendar-day {
        height: 100px;
        border: 1px solid #eee;
        vertical-align: top;
        padding: 5px;
        position: relative;
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
    
    .other-month .day-number {
        color: #aaa;
    }
    
    .current-day {
        background-color: #f0f7ff;
    }
    
    .current-day .day-number {
        background-color: #3498db;
        color: white;
    }
    
    .day-tasks {
        display: flex;
        flex-direction: column;
        gap: 3px;
        overflow-y: auto;
        max-height: 70px;
    }
    
    .day-task {
        padding: 3px 6px;
        border-radius: 3px;
        font-size: 12px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        cursor: pointer;
        transition: transform 0.2s;
    }
    
    .day-task:hover {
        transform: translateY(-1px);
    }
    
    .task-pending {
        background-color: #fff3cd;
        color: #856404;
        border-left: 3px solid #ffc107;
    }
    
    .task-completed {
        background-color: #d4edda;
        color: #155724;
        border-left: 3px solid #2ecc71;
    }
    
    .task-overdue {
        background-color: #f8d7da;
        color: #721c24;
        border-left: 3px solid #dc3545;
    }
    
    /* Scrollbar styling for calendar */
    .calendar-body::-webkit-scrollbar {
        width: 8px;
    }
    
    .calendar-body::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 4px;
    }
    
    .calendar-body::-webkit-scrollbar-thumb {
        background: #ccc;
        border-radius: 4px;
    }
    
    .calendar-body::-webkit-scrollbar-thumb:hover {
        background: #aaa;
    }
    
    .day-tasks::-webkit-scrollbar {
        width: 4px;
    }
    
    .day-tasks::-webkit-scrollbar-track {
        background: transparent;
    }
    
    .day-tasks::-webkit-scrollbar-thumb {
        background: #ddd;
        border-radius: 2px;
    }
    
    .day-tasks::-webkit-scrollbar-thumb:hover {
        background: #ccc;
    }
`;
document.head.appendChild(styleSheet);
</script>

<style>
    /* Add these styles before the existing styles */
    .quote-section {
        position: relative;
        padding: 20px;
        background: #f8f9fa;
        border-radius: 8px;
        margin-bottom: 20px;
    }
    
    .quote-header {
        position: absolute;
        top: 10px;
        right: 10px;
    }
    
    .edit-quote-btn {
        background: none;
        border: none;
        color: #007bff;
        cursor: pointer;
        padding: 5px;
        font-size: 1.1em;
        transition: color 0.2s;
    }
    
    .edit-quote-btn:hover {
        color: #0056b3;
    }
    
    #quoteForm {
        margin-top: 10px;
    }
    
    #quoteForm textarea {
        width: 100%;
        padding: 10px;
        margin-bottom: 10px;
        border: 1px solid #ced4da;
        border-radius: 4px;
        resize: vertical;
        font-size: 0.9em;
    }
    
    .save-quote-btn {
        padding: 6px 15px;
        background-color: #007bff;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.9em;
    }
    
    .save-quote-btn:hover {
        background-color: #0056b3;
    }
    
    .quote-content {
        font-style: italic;
        color: #6c757d;
        margin: 0;
        padding-right: 25px;
    }
    
    .task-notifications {
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid #eee;
    }
    
    .notifications-list {
        max-height: 300px;
        overflow-y: auto;
    }
    
    .notification-item {
        padding: 10px;
        margin-bottom: 10px;
        border-radius: 4px;
        cursor: pointer;
        transition: background-color 0.2s;
    }
    
    .notification-item.unread {
        background-color: #f0f7ff;
    }
    
    .notification-item.read {
        background-color: #f5f5f5;
    }
    
    .notification-item:hover {
        background-color: #e9ecef;
    }
    
    .notification-message {
        font-weight: 500;
        margin-bottom: 5px;
    }
    
    .notification-meta {
        font-size: 0.85em;
        color: #666;
    }
    
    .notification-time {
        margin-right: 10px;
    }
    
    .notification-sender {
        color: #007bff;
    }
    
    .no-notifications {
        text-align: center;
        color: #666;
        padding: 20px;
    }
    
    .notification-item {
        position: relative;
        border-radius: 8px;
        margin-bottom: 10px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .task-info {
        margin-top: 10px;
        padding: 10px;
        background-color: #f8f9fa;
        border-radius: 6px;
        border-left: 4px solid #007bff;
    }
    
    .task-info .task-header h4 {
        margin: 0 0 5px 0;
        color: #2c3e50;
        font-size: 1rem;
    }
    
    .task-info .task-desc {
        margin: 5px 0;
        color: #666;
        font-size: 0.9rem;
    }
    
    .task-info .task-footer {
        margin-top: 8px;
        font-size: 0.85rem;
        color: #666;
    }
    
    .task-info .due-date {
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    
    .notification-item {
        padding: 15px;
        margin-bottom: 10px;
        border-radius: 8px;
        background-color: white;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    
    .notification-item:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }
    
    .notification-item.unread {
        background-color: #f0f7ff;
        border-left: 4px solid #007bff;
    }
    
    .unread-dot {
        position: absolute;
        top: 10px;
        right: 10px;
        width: 8px;
        height: 8px;
        background-color: #007bff;
        border-radius: 50%;
    }
    
    .notification-modal .modal-content {
        max-width: 600px;
        width: 90%;
        max-height: 80vh;
        overflow-y: auto;
        border-radius: 12px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    }
    
    .notification-modal .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px;
        border-bottom: 1px solid #dee2e6;
    }
    
    .notification-modal .modal-header h3 {
        margin: 0;
    }
    
    .notification-modal .modal-header i {
        margin-right: 10px;
    }
    
    .notification-modal .modal-body {
        max-height: 70vh;
        overflow-y: auto;
        padding: 15px;
    }
    
    .task-item {
        cursor: pointer;
        transition: all 0.3s ease;
        border: 1px solid #e0e0e0;
        margin-bottom: 10px;
        padding: 15px;
        border-radius: 8px;
    }
    
    .task-item:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        border-color: #007bff;
    }
    
    .completed-task {
        cursor: default;
    }
    
    .completed-task:hover {
        transform: none;
        box-shadow: none;
        border-color: #e0e0e0;
    }
    
    #taskSubmissionModal .modal-content {
        max-width: 600px;
        width: 90%;
        margin: 30px auto;
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    
    #taskSubmissionModal .modal-header {
        padding: 15px 20px;
        border-bottom: 1px solid #e0e0e0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    #taskSubmissionModal .modal-body {
        padding: 20px;
    }
    
    #taskSubmissionModal .task-details {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 6px;
        margin-bottom: 20px;
    }
    
    #taskSubmissionModal .form-group {
        margin-bottom: 20px;
    }
    
    #taskSubmissionModal label {
        display: block;
        margin-bottom: 5px;
        font-weight: 500;
    }
    
    #taskSubmissionModal textarea {
        width: 100%;
        padding: 8px;
        border: 1px solid #ddd;
        border-radius: 4px;
        resize: vertical;
    }
    
    #taskSubmissionModal .form-actions {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
    }
    
    .btn {
        padding: 8px 16px;
        border-radius: 4px;
        cursor: pointer;
        border: none;
        font-weight: 500;
    }
    
    .btn-primary {
        background: #007bff;
        color: white;
    }
    
    .btn-secondary {
        background: #6c757d;
        color: white;
    }
    
    .btn:hover {
        opacity: 0.9;
    }
    
    .task-columns {
        display: flex;
        gap: 20px;
        margin-top: 20px;
    }
    
    .task-column {
        flex: 1;
        background: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
        min-width: 250px;
    }
    
    .task-list-container {
        margin-top: 10px;
        max-height: 400px;
        overflow-y: auto;
    }
    
    .task-list-container .task-item {
        background: white;
        padding: 10px;
        margin-bottom: 10px;
        border-radius: 4px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .task-list-container .task-item h4 {
        margin: 0 0 5px 0;
        font-size: 14px;
    }
    
    .task-list-container .task-item p {
        margin: 5px 0;
        font-size: 12px;
        color: #666;
    }
    
    #calendarTaskModal .modal-content {
        max-width: 1000px;
        width: 90%;
        max-height: 80vh;
        margin: 30px auto;
    }
    
    #calendarTaskModal .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px 20px;
        border-bottom: 1px solid #dee2e6;
    }
    
    #calendarTaskModal .modal-body {
        padding: 20px;
        overflow-y: auto;
        max-height: calc(80vh - 70px);
    }
    
    .calendar-task-modal {
        max-width: 900px !important;
        width: 90% !important;
        margin: 30px auto !important;
        padding: 0 !important;
        border-radius: 8px !important;
        background: white !important;
    }
    
    .calendar-task-modal .modal-header {
        padding: 15px 20px;
        border-bottom: 1px solid #e0e0e0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .calendar-task-modal .modal-header h3 {
        margin: 0;
        font-size: 1.2rem;
        color: #333;
    }
    
    .calendar-task-modal .close {
        font-size: 24px;
        cursor: pointer;
        color: #666;
    }
    
    .calendar-task-modal .modal-body {
        padding: 20px;
    }
    
    .task-tabs {
        display: flex;
        gap: 20px;
    }
    
    .task-tab {
        flex: 1;
        min-width: 0;
    }
    
    .task-tab h4 {
        margin: 0 0 15px 0;
        padding-bottom: 10px;
        border-bottom: 2px solid #e0e0e0;
        color: #333;
    }
    
    .task-list {
        max-height: 400px;
        overflow-y: auto;
    }
    
    .task-item {
        background: #fff;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 10px;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    
    .task-item:hover {
        border-color: #007bff;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .task-item h4 {
        margin: 0 0 10px 0;
        color: #333;
        font-size: 1rem;
        border: none;
        padding: 0;
    }
    
    .task-item p {
        margin: 5px 0;
        color: #666;
        font-size: 0.9rem;
    }
    
    .task-item .status {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.8rem;
    }
    
    .task-item .status.pending {
        background: #fff3cd;
        color: #856404;
    }
    
    .task-item .status.completed {
        background: #d4edda;
        color: #155724;
    }
    
    .task-item .status.overdue {
        background: #f8d7da;
        color: #721c24;
    }
    
    .task-item .edit-btn {
        display: inline-block;
        padding: 4px 12px;
        background: #007bff;
        color: white;
        border-radius: 4px;
        text-decoration: none;
        font-size: 0.9rem;
        margin-top: 10px;
    }
    
    .task-item .edit-btn:hover {
        background: #0056b3;
    }
    
    /* Calendar day styles */
    .calendar-day {
        cursor: pointer;
    }
    
    .calendar-day.has-tasks .day-number {
        font-weight: bold;
        color: #007bff;
    }
    
    .calendar-day:hover {
        background: #f8f9fa;
    }
</style>

<style>
    /* New Calendar Styles */
    .calendar-container {
        background-color: white;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        margin-bottom: 30px;
        overflow: hidden;
    }
    
    .calendar-header {
        padding: 15px 20px;
        border-bottom: 1px solid #eee;
    }
    
    .calendar-navigation {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .calendar-nav-buttons {
        display: flex;
        align-items: center;
        gap: 5px;
    }
    
    .nav-btn {
        width: 32px;
        height: 32px;
        background-color: #2c3e50;
        color: white;
        border-radius: 4px;
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        transition: background-color 0.2s;
    }
    
    .nav-btn:hover {
        background-color: #1a2530;
    }
    
    .today-btn {
        background-color: #f0f0f0;
        color: #333;
        padding: 6px 12px;
        border-radius: 4px;
        font-size: 14px;
        text-decoration: none;
        margin-left: 10px;
        transition: background-color 0.2s;
    }
    
    .today-btn:hover {
        background-color: #e0e0e0;
    }
    
    .calendar-title {
        font-size: 20px;
        font-weight: 600;
        color: #333;
    }
    
    .calendar-view-options {
        display: flex;
        gap: 5px;
    }
    
    .view-btn {
        background-color: #f0f0f0;
        color: #333;
        padding: 6px 15px;
        border-radius: 4px;
        font-size: 14px;
        text-decoration: none;
        transition: all 0.2s;
    }
    
    .view-btn.active {
        background-color: #3498db;
        color: white;
    }
    
    .view-btn:hover:not(.active) {
        background-color: #e0e0e0;
    }
    
    .calendar-body {
        padding: 10px;
        overflow-y: auto;
        max-height: 600px;
    }
    
    .calendar-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }
    
    .calendar-table th {
        padding: 10px;
        text-align: center;
        font-weight: 600;
        color: #555;
        border-bottom: 1px solid #eee;
    }
    
    .calendar-day {
        height: 100px;
        border: 1px solid #eee;
        vertical-align: top;
        padding: 5px;
        position: relative;
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
    
    .other-month .day-number {
        color: #aaa;
    }
    
    .current-day {
        background-color: #f0f7ff;
    }
    
    .current-day .day-number {
        background-color: #3498db;
        color: white;
    }
    
    .day-tasks {
        display: flex;
        flex-direction: column;
        gap: 3px;
        overflow-y: auto;
        max-height: 70px;
    }
    
    .day-task {
        padding: 3px 6px;
        border-radius: 3px;
        font-size: 12px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        cursor: pointer;
        transition: transform 0.2s;
    }
    
    .day-task:hover {
        transform: translateY(-1px);
    }
    
    .task-pending {
        background-color: #fff3cd;
        color: #856404;
        border-left: 3px solid #ffc107;
    }
    
    .task-completed {
        background-color: #d4edda;
        color: #155724;
        border-left: 3px solid #2ecc71;
    }
    
    .task-overdue {
        background-color: #f8d7da;
        color: #721c24;
        border-left: 3px solid #dc3545;
    }
    
    /* Scrollbar styling for calendar */
    .calendar-body::-webkit-scrollbar {
        width: 8px;
    }
    
    .calendar-body::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 4px;
    }
    
    .calendar-body::-webkit-scrollbar-thumb {
        background: #ccc;
        border-radius: 4px;
    }
    
    .calendar-body::-webkit-scrollbar-thumb:hover {
        background: #aaa;
    }
    
    .day-tasks::-webkit-scrollbar {
        width: 4px;
    }
    
    .day-tasks::-webkit-scrollbar-track {
        background: transparent;
    }
    
    .day-tasks::-webkit-scrollbar-thumb {
        background: #ddd;
        border-radius: 2px;
    }
    
    .day-tasks::-webkit-scrollbar-thumb:hover {
        background: #ccc;
    }
</style>

<script>
// Function to show task details for completed tasks
function showTaskDetails(taskData) {
    // Create the task details modal content
    const modalContent = document.getElementById('taskModalContent');
    
    // Format the due date
    const dueDate = new Date(taskData.due_date);
    const formattedDate = dueDate.toLocaleString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
    
    // Create the HTML content
    let html = `
        <div class="task-details-container">
            <div class="task-details-header">
                <h3>${taskData.title}</h3>
                <span class="task-status completed">
                    <i class="fas fa-check-circle"></i> Completed
                </span>
            </div>
            <div class="task-details-meta">
                <div class="meta-item">
                    <span class="meta-label"><i class="fas fa-calendar-alt"></i> Due Date:</span>
                    <span class="meta-value">${formattedDate}</span>
                </div>
            </div>
    `;
    
    // Fetch task details and submission history
    fetch(`get_task_details.php?id=${taskData.id}`)
        .then(response => response.json())
        .catch(() => {
            // If JSON parsing fails, try to get as text (some endpoints return HTML)
            return fetch(`get_task_details.php?id=${taskData.id}`).then(res => res.text());
        })
        .then(data => {
            // If data is a string (HTML), create a temporary element to parse it
            if (typeof data === 'string') {
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = data;
                
                // Extract description
                const descriptionEl = tempDiv.querySelector('.task-description');
                const description = descriptionEl ? descriptionEl.textContent.trim() : 'No description available';
                
                // Extract completion notes if available
                const notesEl = tempDiv.querySelector('.completion-notes');
                const completionNotes = notesEl ? notesEl.textContent.trim() : '';
                
                html += `
                    <div class="task-details-section">
                        <h4><i class="fas fa-tasks"></i> Description</h4>
                        <div class="task-description">${description}</div>
                    </div>
                `;
                
                if (completionNotes) {
                    html += `
                        <div class="task-details-section">
                            <h4><i class="fas fa-clipboard-check"></i> Completion Notes</h4>
                            <div class="completion-notes">${completionNotes}</div>
                        </div>
                    `;
                }
            } else {
                // If data is JSON
                html += `
                    <div class="task-details-section">
                        <h4><i class="fas fa-tasks"></i> Description</h4>
                        <div class="task-description">${data.description || 'No description available'}</div>
                    </div>
                `;
                
                if (data.completion_notes) {
                    html += `
                        <div class="task-details-section">
                            <h4><i class="fas fa-clipboard-check"></i> Completion Notes</h4>
                            <div class="completion-notes">${data.completion_notes}</div>
                        </div>
                    `;
                }
            }
            
            // Get submission history
            return fetch(`get_task_submissions.php?task_id=${taskData.id}`);
        })
        .then(response => response.json())
        .then(submissions => {
            if (submissions && submissions.length > 0) {
                html += `
                    <div class="task-details-section">
                        <h4><i class="fas fa-history"></i> Submission History</h4>
                        <div class="submission-history">
                `;
                
                submissions.forEach(submission => {
                    const submissionDate = new Date(submission.submitted_at);
                    const formattedSubmissionDate = submissionDate.toLocaleString('en-US', {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                    
                    html += `
                        <div class="submission-item">
                            <div class="submission-header">
                                <span class="submission-date"><i class="fas fa-clock"></i> ${formattedSubmissionDate}</span>
                                <span class="submission-status ${submission.status}">
                                    ${submission.status === 'approved' ? '<i class="fas fa-check-circle"></i> Approved' : 
                                      submission.status === 'rejected' ? '<i class="fas fa-times-circle"></i> Rejected' : 
                                      '<i class="fas fa-clock"></i> Pending'}
                                </span>
                            </div>
                            <div class="submission-content">${submission.submission_text}</div>
                            ${submission.review_notes ? `
                                <div class="review-notes">
                                    <span class="review-notes-label">Review Notes:</span>
                                    <div class="review-notes-content">${submission.review_notes}</div>
                                </div>
                            ` : ''}
                        </div>
                    `;
                });
                
                html += `
                        </div>
                    </div>
                `;
            }
            
            html += `</div>`;
            modalContent.innerHTML = html;
            
            // Show the modal
            document.getElementById('taskModal').style.display = 'block';
        })
        .catch(error => {
            console.error('Error fetching task details:', error);
            modalContent.innerHTML = `
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <p>Failed to load task details. Please try again later.</p>
                </div>
            `;
            document.getElementById('taskModal').style.display = 'block';
        });
}

// Function to close task modal
function closeTaskModal() {
    document.getElementById('taskModal').style.display = 'none';
}
</script>
