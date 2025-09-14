<?php
session_start();
require_once 'db_connection.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Set default report type and period
$report_type = isset($_GET['type']) ? $_GET['type'] : 'daily';
$today = date('Y-m-d');
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : $today;
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : $today;

// Calculate default date ranges based on report type
if ($report_type === 'daily') {
    $start_date = $today;
    $end_date = $today;
    $report_title = "Daily Task Report - " . date('F j, Y', strtotime($today));
} elseif ($report_type === 'weekly') {
    $start_date = date('Y-m-d', strtotime('monday this week'));
    $end_date = date('Y-m-d', strtotime('sunday this week'));
    $report_title = "Weekly Task Report - Week of " . date('F j', strtotime($start_date));
} elseif ($report_type === 'monthly') {
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-t');
    $report_title = "Monthly Task Report - " . date('F Y');
} else {
    $report_title = "Custom Task Report";
}

// Override with user-selected dates if provided
if (isset($_GET['start_date'])) {
    $start_date = $_GET['start_date'];
}
if (isset($_GET['end_date'])) {
    $end_date = $_GET['end_date'];
}

// Get task statistics for the selected period
function getTaskStats($conn, $start_date, $end_date) {
    // Total tasks in the period
    $total_query = "SELECT COUNT(*) as total FROM tasks WHERE DATE(created_at) BETWEEN ? AND ?";
    $stmt = $conn->prepare($total_query);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $total_result = $stmt->get_result()->fetch_assoc();
    $total_tasks = $total_result['total'];
    
    // Tasks by status
    $status_query = "SELECT status, COUNT(*) as count FROM tasks WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY status";
    $stmt = $conn->prepare($status_query);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $status_result = $stmt->get_result();
    
    $tasks_by_status = [
        'pending' => 0,
        'completed' => 0,
        'overdue' => 0
    ];
    
    while ($row = $status_result->fetch_assoc()) {
        $tasks_by_status[$row['status']] = $row['count'];
    }
    
    // Check for overdue tasks
    $overdue_query = "SELECT COUNT(*) as count FROM tasks 
                      WHERE status = 'pending' AND due_date < CURDATE() 
                      AND DATE(created_at) BETWEEN ? AND ?";
    $stmt = $conn->prepare($overdue_query);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $overdue_result = $stmt->get_result()->fetch_assoc();
    $tasks_by_status['overdue'] = $overdue_result['count'];
    
    // Tasks by priority
    $priority_query = "SELECT priority, COUNT(*) as count FROM tasks WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY priority";
    $stmt = $conn->prepare($priority_query);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $priority_result = $stmt->get_result();
    
    $tasks_by_priority = [
        'low' => 0,
        'medium' => 0,
        'high' => 0
    ];
    
    while ($row = $priority_result->fetch_assoc()) {
        $tasks_by_priority[$row['priority']] = $row['count'];
    }
    
    // Tasks by user
    $user_query = "SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) as user_name, COUNT(t.id) as task_count 
                  FROM users u 
                  LEFT JOIN tasks t ON u.id = t.assigned_to AND DATE(t.created_at) BETWEEN ? AND ? 
                  WHERE u.role = 'staff' 
                  GROUP BY u.id 
                  ORDER BY task_count DESC";
    $stmt = $conn->prepare($user_query);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $user_result = $stmt->get_result();
    
    $tasks_by_user = [];
    while ($row = $user_result->fetch_assoc()) {
        $tasks_by_user[] = $row;
    }
    
    // Completion rate
    $completion_rate = $total_tasks > 0 ? round(($tasks_by_status['completed'] / $total_tasks) * 100, 2) : 0;
    
    // Average completion time
    $completion_time_query = "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, completed_at)) as avg_time 
                             FROM tasks 
                             WHERE status = 'completed' AND completed_at IS NOT NULL 
                             AND DATE(created_at) BETWEEN ? AND ?";
    $stmt = $conn->prepare($completion_time_query);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $time_result = $stmt->get_result()->fetch_assoc();
    $avg_completion_time = $time_result['avg_time'] ? round($time_result['avg_time'], 1) : 0;
    
    // Recent tasks
    $recent_tasks_query = "SELECT t.id, t.title, t.status, t.priority, t.due_date, 
                          CONCAT(u.first_name, ' ', u.last_name) as assigned_to 
                          FROM tasks t 
                          LEFT JOIN users u ON t.assigned_to = u.id 
                          WHERE DATE(t.created_at) BETWEEN ? AND ? 
                          ORDER BY t.created_at DESC LIMIT 10";
    $stmt = $conn->prepare($recent_tasks_query);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $recent_tasks_result = $stmt->get_result();
    
    $recent_tasks = [];
    while ($row = $recent_tasks_result->fetch_assoc()) {
        $recent_tasks[] = $row;
    }
    
    return [
        'total_tasks' => $total_tasks,
        'tasks_by_status' => $tasks_by_status,
        'tasks_by_priority' => $tasks_by_priority,
        'tasks_by_user' => $tasks_by_user,
        'completion_rate' => $completion_rate,
        'avg_completion_time' => $avg_completion_time,
        'recent_tasks' => $recent_tasks
    ];
}

$stats = getTaskStats($conn, $start_date, $end_date);

// Get user performance ratings
$ratings_query = "SELECT ur.user_id, ur.rating, ur.last_updated, 
                 CONCAT(u.first_name, ' ', u.last_name) as user_name
                 FROM user_ratings ur
                 JOIN users u ON ur.user_id = u.id
                 WHERE u.role = 'staff'
                 ORDER BY ur.rating DESC";
$ratings_result = $conn->query($ratings_query);

$user_ratings = [];
if ($ratings_result && $ratings_result->num_rows > 0) {
    while ($row = $ratings_result->fetch_assoc()) {
        $user_ratings[] = $row;
    }
}

// Get task trend data for charts
function getTaskTrends($conn, $start_date, $end_date, $report_type) {
    $interval = 'day';
    $format = '%Y-%m-%d';
    $date_format = 'M d';
    
    if ($report_type === 'weekly') {
        $interval = 'day';
        $format = '%Y-%m-%d';
        $date_format = 'D';
    } elseif ($report_type === 'monthly') {
        $interval = 'day';
        $format = '%Y-%m-%d';
        $date_format = 'd';
    } elseif ($report_type === 'custom' && (strtotime($end_date) - strtotime($start_date)) > 60*60*24*30) {
        $interval = 'week';
        $format = '%Y-%u';
        $date_format = 'Week %U';
    }
    
    $trend_query = "SELECT 
                    DATE_FORMAT(created_at, '$format') as date_group,
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
                    FROM tasks 
                    WHERE DATE(created_at) BETWEEN ? AND ?
                    GROUP BY date_group
                    ORDER BY date_group";
    
    $stmt = $conn->prepare($trend_query);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $trend_result = $stmt->get_result();
    
    $trends = [];
    while ($row = $trend_result->fetch_assoc()) {
        $date_label = $row['date_group'];
        if ($interval === 'day') {
            $date_label = date($date_format, strtotime($row['date_group']));
        } elseif ($interval === 'week') {
            list($year, $week) = explode('-', $row['date_group']);
            $date_label = "Week $week";
        }
        
        $trends[] = [
            'date' => $date_label,
            'total' => $row['total'],
            'completed' => $row['completed'],
            'pending' => $row['pending']
        ];
    }
    
    return $trends;
}

$trends = getTaskTrends($conn, $start_date, $end_date, $report_type);

// Convert data to JSON for charts
$trends_json = json_encode($trends);
$status_json = json_encode([
    ['status' => 'Pending', 'count' => $stats['tasks_by_status']['pending']],
    ['status' => 'Completed', 'count' => $stats['tasks_by_status']['completed']],
    ['status' => 'Overdue', 'count' => $stats['tasks_by_status']['overdue']]
]);
$priority_json = json_encode([
    ['priority' => 'Low', 'count' => $stats['tasks_by_priority']['low']],
    ['priority' => 'Medium', 'count' => $stats['tasks_by_priority']['medium']],
    ['priority' => 'High', 'count' => $stats['tasks_by_priority']['high']]
]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Reports - InboxSandy</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard1.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: #4a90e2;
            --primary-light: #6ba4e7;
            --primary-dark: #357abd;
            --secondary-color: #f8f9fa;
            --text-color: #333;
            --text-light: #6c757d;
            --text-dark: #212529;
            --border-color: #dee2e6;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --radius-sm: 4px;
            --radius-md: 8px;
            --radius-lg: 12px;
            --shadow-sm: 0 2px 4px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f0f2f5;
            margin: 0;
            padding: 0;
            color: var(--text-color);
        }
        
        .container {
            display: flex;
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .sidebar {
            width: 300px;
            background-color: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            padding: 25px;
            margin-right: 20px;
            height: fit-content;
        }
        
        .main-content {
            flex: 1;
            background-color: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            padding: 25px;
        }
        
        .header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            padding: 8px 16px;
            border-radius: 6px;
        }
        
        .back-button:hover {
            background-color: rgba(74, 144, 226, 0.1);
            color: var(--primary-dark);
            transform: translateX(-4px);
        }
        
        .header h1 {
            margin: 0 20px;
            color: var(--text-dark);
            font-size: 24px;
            font-weight: 600;
        }
        
        .report-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background-color: var(--primary-color);
            color: white;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            padding: 8px 16px;
            border-radius: 6px;
            margin-left: auto;
            box-shadow: var(--shadow-sm);
        }
        
        .report-button:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            color: white;
            text-decoration: none;
        }
        
        .search-box {
            width: 100%;
            padding: 12px 20px;
            margin-bottom: 20px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .search-box:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.2);
        }
        
        .report-filters {
            display: flex;
            margin-bottom: 20px;
        }
        
        .filter-btn {
            background-color: var(--secondary-color);
            border: none;
            padding: 10px 20px;
            border-radius: var(--radius-md);
            margin-right: 10px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .filter-btn:hover, .filter-btn.active {
            background-color: var(--primary-color);
            color: white;
        }
        
        .stats-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            flex: 1;
            min-width: 200px;
            background-color: white;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            padding: 20px;
            transition: all 0.3s ease;
            border-top: 4px solid var(--primary-color);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }
        
        .stat-card.pending {
            border-top-color: var(--warning-color);
        }
        
        .stat-card.completed {
            border-top-color: var(--success-color);
        }
        
        .stat-card.overdue {
            border-top-color: var(--danger-color);
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--text-dark);
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-light);
            font-weight: 500;
        }
        
        .charts-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .chart-container {
            flex: 1;
            min-width: 300px;
            background-color: white;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            padding: 20px;
        }
        
        .chart-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--text-dark);
            text-align: center;
        }
        
        .tables-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .table-container {
            flex: 1;
            min-width: 300px;
            background-color: white;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            padding: 20px;
        }
        
        .table-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .table th {
            background-color: var(--secondary-color);
            color: var(--text-dark);
            font-weight: 600;
            padding: 12px 15px;
            text-align: left;
            border-top: none;
        }
        
        .table td {
            padding: 12px 15px;
            vertical-align: middle;
            border-top: 1px solid var(--border-color);
        }
        
        .table tr:hover {
            background-color: rgba(0, 0, 0, 0.02);
        }
        
        .badge {
            padding: 5px 10px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-pending {
            background-color: var(--warning-color);
            color: white;
        }
        
        .badge-completed {
            background-color: var(--success-color);
            color: white;
        }
        
        .badge-overdue {
            background-color: var(--danger-color);
            color: white;
        }
        
        .badge-low {
            background-color: var(--primary-color);
            color: white;
        }
        
        .badge-medium {
            background-color: var(--warning-color);
            color: white;
        }
        
        .badge-high {
            background-color: var(--danger-color);
            color: white;
        }
        
        .date-range {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .date-range input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
        }
        
        .date-range button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .date-range button:hover {
            background-color: var(--primary-dark);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-dark);
        }
        
        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 16px;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            font-weight: 600;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        @media print {
            body {
                background: white;
            }
            
            .container {
                display: block;
                padding: 0;
            }
            
            .sidebar, .no-print {
                display: none !important;
            }
            
            .main-content {
                box-shadow: none;
                padding: 0;
            }
            
            .chart-container, .stat-card, .table-container {
                box-shadow: none;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <h2>Report Options</h2>
            <form method="GET">
                <div class="form-group">
                    <label for="report-type">Report Type</label>
                    <select name="type" id="report-type" class="form-control">
                        <option value="daily" <?php echo $report_type === 'daily' ? 'selected' : ''; ?>>Daily Report</option>
                        <option value="weekly" <?php echo $report_type === 'weekly' ? 'selected' : ''; ?>>Weekly Report</option>
                        <option value="monthly" <?php echo $report_type === 'monthly' ? 'selected' : ''; ?>>Monthly Report</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="start-date">Start Date</label>
                    <input type="date" id="start-date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                </div>
                
                <div class="form-group">
                    <label for="end-date">End Date</label>
                    <input type="date" id="end-date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                </div>
                
                <button type="submit" class="btn-primary">
                    <i class="fas fa-filter"></i> Generate Report
                </button>
            </form>
            
            <div class="mt-4">
                <button onclick="window.print()" class="btn-primary no-print">
                    <i class="fas fa-file-export"></i> Export Report
                </button>
            </div>
        </div>
        
        <div class="main-content">
            <div class="header">
                <a href="dashboard.php" class="back-button no-print">
                    <i class="fas fa-arrow-left"></i>
                    Back to Dashboard
                </a>
                <h1>
                    <i class="fas fa-chart-line"></i> <?php echo $report_title; ?>
                </h1>
            </div>
            
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['total_tasks']; ?></div>
                    <div class="stat-label">Total Tasks</div>
                </div>
                <div class="stat-card pending">
                    <div class="stat-value"><?php echo $stats['tasks_by_status']['pending']; ?></div>
                    <div class="stat-label">Pending Tasks</div>
                </div>
                <div class="stat-card completed">
                    <div class="stat-value"><?php echo $stats['tasks_by_status']['completed']; ?></div>
                    <div class="stat-label">Completed Tasks</div>
                </div>
                <div class="stat-card overdue">
                    <div class="stat-value"><?php echo $stats['tasks_by_status']['overdue']; ?></div>
                    <div class="stat-label">Overdue Tasks</div>
                </div>
            </div>
            
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['completion_rate']; ?>%</div>
                    <div class="stat-label">Task Completion Rate</div>
                    <div class="progress mt-2">
                        <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $stats['completion_rate']; ?>%" aria-valuenow="<?php echo $stats['completion_rate']; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['avg_completion_time']; ?> hrs</div>
                    <div class="stat-label">Average Completion Time</div>
                </div>
            </div>
            
            <div class="charts-row">
                <div class="chart-container">
                    <h3 class="chart-title">Task Trends</h3>
                    <canvas id="taskTrendChart"></canvas>
                </div>
                <div class="chart-container">
                    <h3 class="chart-title">Tasks by Status</h3>
                    <canvas id="statusChart"></canvas>
                </div>
                <div class="chart-container">
                    <h3 class="chart-title">Tasks by Priority</h3>
                    <canvas id="priorityChart"></canvas>
                </div>
            </div>
            
            <div class="tables-row">
                <div class="table-container">
                    <h3 class="table-title">
                        <i class="fas fa-users"></i> Tasks by Staff Member
                    </h3>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Staff Member</th>
                                <th>Task Count</th>
                                <th>Progress</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['tasks_by_user'] as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['user_name']); ?></td>
                                <td><?php echo $user['task_count']; ?></td>
                                <td>
                                    <div class="progress">
                                        <div class="progress-bar bg-info" role="progressbar" 
                                             style="width: <?php echo ($stats['total_tasks'] > 0) ? (($user['task_count'] / $stats['total_tasks']) * 100) : 0; ?>%" 
                                             aria-valuenow="<?php echo $user['task_count']; ?>" 
                                             aria-valuemin="0" 
                                             aria-valuemax="<?php echo $stats['total_tasks']; ?>"></div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="table-container">
                    <h3 class="table-title">
                        <i class="fas fa-tasks"></i> Recent Tasks
                    </h3>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Task</th>
                                <th>Assigned To</th>
                                <th>Status</th>
                                <th>Priority</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['recent_tasks'] as $task): ?>
                            <tr>
                                <td>
                                    <a href="review_task.php?id=<?php echo $task['id']; ?>">
                                        <?php echo htmlspecialchars($task['title']); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($task['assigned_to']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $task['status']; ?>">
                                        <?php echo ucfirst($task['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $task['priority']; ?>">
                                        <?php echo ucfirst($task['priority']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'supervisor'): ?>
            <div class="tables-row">
                <div class="table-container">
                    <h3 class="table-title">
                        <i class="fas fa-star"></i> Staff Performance Ratings
                        <span class="badge badge-primary ml-2">Supervisor Only</span>
                    </h3>
                    <p class="mb-3">Performance ratings are automatically calculated based on task completion rate, timeliness, and quality of submissions.</p>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Staff Member</th>
                                <th>Rating (0-5)</th>
                                <th>Performance</th>
                                <th>Last Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($user_ratings as $rating): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($rating['user_name']); ?></td>
                                <td><?php echo number_format($rating['rating'], 2); ?></td>
                                <td>
                                    <?php 
                                    $rating_value = floatval($rating['rating']);
                                    $rating_class = '';
                                    $rating_text = '';
                                    
                                    if ($rating_value >= 4.5) {
                                        $rating_class = 'success';
                                        $rating_text = 'Excellent';
                                    } elseif ($rating_value >= 3.5) {
                                        $rating_class = 'info';
                                        $rating_text = 'Good';
                                    } elseif ($rating_value >= 2.5) {
                                        $rating_class = 'warning';
                                        $rating_text = 'Average';
                                    } else {
                                        $rating_class = 'danger';
                                        $rating_text = 'Needs Improvement';
                                    }
                                    ?>
                                    <span class="badge badge-<?php echo $rating_class; ?>">
                                        <?php echo $rating_text; ?>
                                    </span>
                                </td>
                                <td><?php echo date('M j, Y g:i A', strtotime($rating['last_updated'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="mt-3">
                        <a href="update_user_rating.php" class="btn-primary" style="display: inline-block; text-decoration: none; padding: 8px 16px;">
                            <i class="fas fa-sync"></i> Update Ratings
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Task Trend Chart
        var trendCtx = document.getElementById('taskTrendChart').getContext('2d');
        var trendData = <?php echo $trends_json; ?>;
        
        var labels = trendData.map(item => item.date);
        var totalData = trendData.map(item => item.total);
        var completedData = trendData.map(item => item.completed);
        var pendingData = trendData.map(item => item.pending);
        
        var trendChart = new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Total Tasks',
                        data: totalData,
                        borderColor: '#4a90e2',
                        backgroundColor: 'rgba(74, 144, 226, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3
                    },
                    {
                        label: 'Completed Tasks',
                        data: completedData,
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3
                    },
                    {
                        label: 'Pending Tasks',
                        data: pendingData,
                        borderColor: '#ffc107',
                        backgroundColor: 'rgba(255, 193, 7, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
        
        // Status Chart
        var statusCtx = document.getElementById('statusChart').getContext('2d');
        var statusData = <?php echo $status_json; ?>;
        
        var statusChart = new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: statusData.map(item => item.status),
                datasets: [{
                    data: statusData.map(item => item.count),
                    backgroundColor: [
                        '#ffc107',
                        '#28a745',
                        '#dc3545'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                    }
                }
            }
        });
        
        // Priority Chart
        var priorityCtx = document.getElementById('priorityChart').getContext('2d');
        var priorityData = <?php echo $priority_json; ?>;
        
        var priorityChart = new Chart(priorityCtx, {
            type: 'doughnut',
            data: {
                labels: priorityData.map(item => item.priority),
                datasets: [{
                    data: priorityData.map(item => item.count),
                    backgroundColor: [
                        '#4a90e2',
                        '#ffc107',
                        '#dc3545'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                    }
                }
            }
        });
    </script>
</body>
</html>
