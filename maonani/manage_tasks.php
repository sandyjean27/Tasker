<?php
session_start();
require_once 'db_connection.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Handle task creation
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['create_task'])) {
    $title = $conn->real_escape_string($_POST['title']);
    $description = $conn->real_escape_string($_POST['description']);
    $due_date = $conn->real_escape_string($_POST['due_date']);
    $priority = $conn->real_escape_string($_POST['priority']);
    $assigned_to = isset($_POST['assigned_to']) ? $_POST['assigned_to'] : null;
    
    if ($assigned_to) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Create individual task
            $create_task = $conn->prepare("INSERT INTO tasks (title, description, created_by, assigned_to, due_date, status, priority) VALUES (?, ?, ?, ?, ?, 'pending', ?)");
            $create_task->bind_param("ssiiss", $title, $description, $_SESSION['user_id'], $assigned_to, $due_date, $priority);
            
            if ($create_task->execute()) {
                $task_id = $conn->insert_id;
                
                // Handle file uploads
                if (!empty($_FILES['attachments']['name'][0])) {
                    $upload_dir = "uploads/";
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                        $file_name = $_FILES['attachments']['name'][$key];
                        $file_size = $_FILES['attachments']['size'][$key];
                        $file_tmp = $_FILES['attachments']['tmp_name'][$key];
                        $file_type = $_FILES['attachments']['type'][$key];
                        
                        // Generate unique filename
                        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                        $unique_file_name = uniqid() . '.' . $file_ext;
                        $upload_path = $upload_dir . $unique_file_name;
                        
                        if (move_uploaded_file($file_tmp, $upload_path)) {
                            // Save file information to database
                            $stmt = $conn->prepare("INSERT INTO task_attachments (task_id, file_name, file_path, uploaded_by) VALUES (?, ?, ?, ?)");
                            $stmt->bind_param("issi", $task_id, $file_name, $unique_file_name, $_SESSION['user_id']);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }
                }
                
                // Create notification for assigned user
                $notify_stmt = $conn->prepare("INSERT INTO notifications (user_id, task_id, type, message) VALUES (?, ?, 'task_assigned', 'You have been assigned a new task')");
                $notify_stmt->bind_param("ii", $assigned_to, $task_id);
                $notify_stmt->execute();
                $notify_stmt->close();
                
                $conn->commit();
                $_SESSION['success_message'] = "Task created successfully.";
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = "Error creating task: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = "Please select a staff member to assign the task to.";
    }
    
    header("Location: manage_tasks.php");
    exit();
}

// Handle task deletion
if (isset($_POST['delete_task'])) {
    $task_id = (int)$_POST['task_id'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Delete task attachments
        $delete_attachments = $conn->prepare("DELETE FROM task_attachments WHERE task_id = ?");
        $delete_attachments->bind_param("i", $task_id);
        $delete_attachments->execute();
        
        // Delete task submissions
        $delete_submissions = $conn->prepare("DELETE FROM task_submissions WHERE task_id = ?");
        $delete_submissions->bind_param("i", $task_id);
        $delete_submissions->execute();
        
        // Delete task
        $delete_task = $conn->prepare("DELETE FROM tasks WHERE id = ?");
        $delete_task->bind_param("i", $task_id);
        $delete_task->execute();
        
        $conn->commit();
        $_SESSION['success_message'] = "Task deleted successfully.";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "Error deleting task: " . $e->getMessage();
    }
    
    header("Location: manage_tasks.php");
    exit();
}

// Handle attachment deletion
if (isset($_POST['delete_attachment'])) {
    $attachment_id = (int)$_POST['delete_attachment'];
    
    // Get the file name first
    $stmt = $conn->prepare("SELECT file_name FROM task_attachments WHERE id = ?");
    $stmt->bind_param("i", $attachment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $attachment = $result->fetch_assoc();
    $stmt->close();
    
    if ($attachment) {
        // Delete the physical file
        $file_path = "uploads/" . $attachment['file_name'];
        if (file_exists($file_path)) {
            unlink($file_path);
        }
        
        // Delete the database record
        $stmt = $conn->prepare("DELETE FROM task_attachments WHERE id = ?");
        $stmt->bind_param("i", $attachment_id);
        $stmt->execute();
        $stmt->close();
    }
    
    header("Location: edit_task.php?id=" . $_POST['task_id']);
    exit();
}

// Handle task editing
if (isset($_POST['edit_task'])) {
    $task_id = (int)$_POST['task_id'];
    $title = $_POST['title'];
    $description = $_POST['description'];
    $assigned_to = $_POST['assigned_to'][0]; // Get first selected staff
    $due_date = $_POST['due_date'];
    
    // Update task details
    $stmt = $conn->prepare("UPDATE tasks SET title = ?, description = ?, assigned_to = ?, due_date = ? WHERE id = ?");
    $stmt->bind_param("ssisi", $title, $description, $assigned_to, $due_date, $task_id);
    $stmt->execute();
    $stmt->close();
    
    // Handle new file attachments
    if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
        // Create uploads directory if it doesn't exist
        if (!file_exists('uploads')) {
            mkdir('uploads', 0777, true);
        }
        
        // Process each uploaded file
        foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['attachments']['error'][$key] === 0) {
                $file_name = uniqid() . '_' . $_FILES['attachments']['name'][$key];
                $file_path = 'uploads/' . $file_name;
                
                if (move_uploaded_file($tmp_name, $file_path)) {
                    // Save attachment record in database
                    $stmt = $conn->prepare("INSERT INTO task_attachments (task_id, file_name) VALUES (?, ?)");
                    $stmt->bind_param("is", $task_id, $file_name);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }
    
    header("Location: manage_tasks.php");
    exit();
}

// Fetch all staff members for the dropdown
$staff_result = $conn->query("SELECT id, first_name, last_name FROM users WHERE role = 'staff' ORDER BY last_name, first_name");

// Get all tasks with user information
$tasks_query = "SELECT t.*, 
                u1.first_name as creator_first_name, u1.last_name as creator_last_name,
                u2.first_name as assigned_first_name, u2.last_name as assigned_last_name,
                (SELECT COUNT(*) FROM task_attachments WHERE task_id = t.id) as attachment_count,
                (SELECT COUNT(*) FROM task_submissions WHERE task_id = t.id) as submission_count
                FROM tasks t
                LEFT JOIN users u1 ON t.created_by = u1.id
                LEFT JOIN users u2 ON t.assigned_to = u2.id
                ORDER BY t.created_at DESC";

$tasks_result = $conn->query($tasks_query);
if (!$tasks_result) {
    die("Error fetching tasks: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Tasks - InboxSandy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4a90e2;
            --primary-light: #6ba4e7;
            --primary-dark: #357abd;
            --secondary-color: #f5f6fa;
            --danger-color: #e74c3c;
            --success-color: #2ecc71;
            --warning-color: #f1c40f;
            --text-color: #2c3e50;
            --text-light: #7f8c8d;
            --border-color: #e0e0e0;
            --background-color: #f8f9fa;
            --card-background: #ffffff;
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 8px rgba(0,0,0,0.1);
            --shadow-lg: 0 8px 16px rgba(0,0,0,0.1);
            --gradient-bg: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--gradient-bg);
            color: var(--text-color);
            line-height: 1.6;
            min-height: 100vh;
        }

        .container {
            max-width: 1600px;
            margin: 20px auto;
            padding: 24px;
            display: flex;
            gap: 24px;
        }

        .sidebar {
            flex: 0 0 380px;
            background: var(--card-background);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-lg);
            height: calc(100vh - 48px);
            position: sticky;
            top: 24px;
            overflow-y: auto;
            transition: var(--transition);
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }

        .main-content {
            flex: 1;
            background: var(--card-background);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-lg);
            height: calc(100vh - 48px);
            overflow-y: auto;
            transition: var(--transition);
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }

        .header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .header h1 {
            margin: 0 20px;
            color: var(--text-dark);
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
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
            font-size: 0.95rem;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 0.95rem;
            transition: var(--transition);
            background: var(--background-color);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(74, 144, 226, 0.1);
            background: white;
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        .btn {
            padding: 12px 24px;
            border-radius: var(--radius-md);
            border: none;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: var(--transition);
            text-decoration: none;
            box-shadow: var(--shadow-sm);
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn:active {
            transform: translateY(0);
        }

        .task-filters {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            background: var(--background-color);
            border-radius: var(--radius-lg);
            padding: 8px;
            box-shadow: var(--shadow-sm);
        }

        .filter-btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius-md);
            background: transparent;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
            color: var(--text-light);
            flex: 1;
            text-align: center;
        }

        .filter-btn:hover {
            color: var(--primary-color);
            background: rgba(74, 144, 226, 0.1);
        }

        .filter-btn.active {
            background: var(--primary-color);
            color: white;
            box-shadow: var(--shadow-sm);
        }

        .task-list {
            display: grid;
            gap: 20px;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
        }

        .task-card {
            background: var(--card-background);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 24px;
            transition: var(--transition);
            display: flex;
            flex-direction: column;
            gap: 16px;
            box-shadow: var(--shadow-sm);
            cursor: pointer;
        }

        .task-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-color);
        }

        .task-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
        }

        .task-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-color);
            line-height: 1.4;
        }

        .task-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            font-size: 0.9rem;
            color: var(--text-light);
            background: var(--background-color);
            padding: 12px;
            border-radius: var(--radius-md);
        }

        .task-description {
            color: var(--text-color);
            font-size: 0.95rem;
            line-height: 1.6;
            flex-grow: 1;
            padding: 12px;
            background: var(--background-color);
            border-radius: var(--radius-md);
        }

        .task-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 16px;
            border-top: 1px solid var(--border-color);
            margin-top: auto;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: var(--shadow-sm);
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-completed {
            background: #d1e7dd;
            color: #0f5132;
        }

        .task-actions {
            display: flex;
            gap: 12px;
        }

        .search-box {
            width: 100%;
            padding: 16px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-lg);
            margin-bottom: 24px;
            font-size: 1rem;
            background: var(--background-color);
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
        }

        .search-box:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(74, 144, 226, 0.1);
            background: white;
        }

        .attachment-indicator,
        .due-date {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-light);
            font-size: 0.9rem;
            padding: 8px 12px;
            border-radius: var(--radius-md);
            background: white;
            box-shadow: var(--shadow-sm);
        }

        .overdue {
            color: var(--danger-color);
            font-weight: 600;
        }

        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: var(--text-light);
            font-size: 1.1rem;
            background: var(--background-color);
            border-radius: var(--radius-lg);
            margin: 24px 0;
            box-shadow: var(--shadow-sm);
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--background-color);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary-light);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-color);
        }

        @media (max-width: 1200px) {
            .task-list {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .container {
                flex-direction: column;
                padding: 16px;
                margin: 0;
            }

            .sidebar {
                position: static;
                height: auto;
                width: 100%;
            }

            .main-content {
                height: auto;
            }

            .task-list {
                grid-template-columns: 1fr;
            }

            .task-meta {
                flex-direction: column;
                gap: 8px;
            }

            .header {
                flex-direction: column;
                gap: 16px;
                align-items: flex-start;
            }

            .back-button {
                width: 100%;
                justify-content: center;
            }
        }
    </style>

    <!-- Add this right before the closing </head> tag -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js"></script>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <h2 style="margin-bottom: 20px;">Create New Task</h2>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Title</label>
                    <input type="text" name="title" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" class="form-control" required></textarea>
                </div>

                <div class="form-group">
                    <label>Assign To</label>
                    <select name="assigned_to" class="form-control" required>
                        <?php 
                        $staff_result->data_seek(0);
                        while ($staff = $staff_result->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $staff['id']; ?>">
                                <?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Due Date</label>
                    <input type="datetime-local" name="due_date" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>Priority</label>
                    <select name="priority" class="form-control" required>
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Attachments</label>
                    <input type="file" name="attachments[]" class="form-control" multiple>
                </div>

                <button type="submit" name="create_task" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-plus"></i> Create Task
                </button>
            </form>
        </div>

        <div class="main-content">
            <div class="header">
                <a href="dashboard.php" class="back-button">
                    <i class="fas fa-arrow-left"></i>
                    Back to Dashboard
                </a>
                <h1>Manage Tasks</h1>
                <a href="task_reports.php" class="report-button">
                    <i class="fas fa-chart-line"></i>
                    View Task Reports
                </a>
            </div>

            <input type="text" id="taskSearch" class="search-box" placeholder="Search tasks by title, description, or assigned staff...">

            <div class="task-filters">
                <button class="filter-btn active" data-filter="all">All Tasks</button>
                <button class="filter-btn" data-filter="pending">Pending</button>
                <button class="filter-btn" data-filter="completed">Completed</button>
                <button class="filter-btn" data-filter="overdue">Overdue</button>
            </div>

            <div class="task-list">
                <?php 
                if ($tasks_result->num_rows > 0) {
                    while ($task = $tasks_result->fetch_assoc()) {
                        $is_overdue = strtotime($task['due_date']) < time() && $task['status'] !== 'completed';
                        ?>
                        <div class="task-card" data-status="<?php echo $task['status']; ?>" 
                             data-search="<?php echo strtolower($task['title'] . ' ' . $task['description'] . ' ' . 
                                                              $task['assigned_first_name'] . ' ' . $task['assigned_last_name']); ?>">
                            <div class="task-header">
                                <div class="task-title">
                                    <a href="review_task.php?id=<?php echo $task['id']; ?>" class="task-link">
                                        <?php echo htmlspecialchars($task['title']); ?>
                                    </a>
                                </div>
                                <span class="status-badge status-<?php echo $task['status']; ?>">
                                    <?php echo ucfirst($task['status']); ?>
                                </span>
                            </div>

                            <div class="task-meta">
                                <div class="due-date <?php echo $is_overdue ? 'overdue' : ''; ?>">
                                    <i class="far fa-calendar"></i>
                                    <?php 
                                    echo date('M j, Y g:i A', strtotime($task['due_date']));
                                    if ($is_overdue) echo ' (Overdue)';
                                    ?>
                                </div>
                                <div class="attachment-indicator">
                                    <i class="fas fa-paperclip"></i>
                                    <?php echo $task['attachment_count']; ?> attachments
                                </div>
                                <div>
                                    <i class="fas fa-user"></i>
                                    <?php echo htmlspecialchars($task['assigned_first_name'] . ' ' . $task['assigned_last_name']); ?>
                                </div>
                            </div>

                            <div class="task-description">
                                <?php echo nl2br(htmlspecialchars(substr($task['description'], 0, 150) . (strlen($task['description']) > 150 ? '...' : ''))); ?>
                            </div>

                            <div class="task-footer">
                                <div class="task-info">
                                    <small>Created by: <?php echo htmlspecialchars($task['creator_first_name'] . ' ' . $task['creator_last_name']); ?></small>
                                    <?php if ($task['submission_count'] > 0): ?>
                                    <span class="submission-badge">
                                        <i class="fas fa-file-alt"></i> <?php echo $task['submission_count']; ?> submission(s)
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <div class="task-actions">
                                    <a href="review_task.php?id=<?php echo $task['id']; ?>" class="btn btn-info">
                                        <i class="fas fa-eye"></i> Review
                                    </a>
                                    <a href="edit_task.php?id=<?php echo $task['id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <button class="btn btn-danger" onclick="deleteTask(<?php echo $task['id']; ?>)">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php
                    }
                } else {
                    echo '<div class="empty-state">No tasks found. Create a new task to get started.</div>';
                }
                ?>
            </div>
        </div>
    </div>

    <script>
        // Search functionality
        document.getElementById('taskSearch').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const taskCards = document.querySelectorAll('.task-card');

            taskCards.forEach(card => {
                const searchText = card.dataset.search;
                if (searchText.includes(searchTerm)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        // Filter functionality
        document.querySelectorAll('.filter-btn').forEach(button => {
            button.addEventListener('click', function() {
                // Remove active class from all buttons
                document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
                // Add active class to clicked button
                this.classList.add('active');

                const filter = this.dataset.filter;
                const taskCards = document.querySelectorAll('.task-card');

                taskCards.forEach(card => {
                    const status = card.dataset.status;
                    const dueDate = new Date(card.querySelector('.due-date').textContent);
                    const isOverdue = dueDate < new Date() && status !== 'completed';

                    switch(filter) {
                        case 'all':
                            card.style.display = '';
                            break;
                        case 'pending':
                            card.style.display = status === 'pending' ? '' : 'none';
                            break;
                        case 'completed':
                            card.style.display = status === 'completed' ? '' : 'none';
                            break;
                        case 'overdue':
                            card.style.display = isOverdue ? '' : 'none';
                            break;
                    }
                });
            });
        });

        // Delete confirmation
        function deleteTask(taskId) {
            if (confirm('Are you sure you want to delete this task? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="delete_task" value="1">
                    <input type="hidden" name="task_id" value="${taskId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>