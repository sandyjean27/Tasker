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

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Get task ID from URL
$task_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch task details
$task_query = "SELECT t.*, u2.id as assigned_to
               FROM tasks t
               JOIN users u2 ON t.assigned_to = u2.id
               WHERE t.id = ?";
$stmt = $conn->prepare($task_query);
$stmt->bind_param("i", $task_id);
$stmt->execute();
$task = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$task) {
    header("Location: manage_tasks.php");
    exit();
}

// Fetch existing attachments
$attachments_query = "SELECT * FROM task_attachments WHERE task_id = ?";
$stmt = $conn->prepare($attachments_query);
$stmt->bind_param("i", $task_id);
$stmt->execute();
$attachments = $stmt->get_result();
$stmt->close();

// Fetch all staff members for the dropdown
$staff_result = $conn->query("SELECT id, first_name, last_name FROM users WHERE role = 'staff' ORDER BY last_name, first_name");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Task - InboxSandy</title>
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
            max-width: 800px;
            margin: 40px auto;
            padding: 24px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
        }

        .header h1 {
            font-size: 24px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header h1 i {
            color: var(--primary-color);
        }

        .back-button {
            color: var(--text-color);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            padding: 10px 20px;
            border-radius: var(--radius-md);
            background: var(--secondary-color);
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
        }

        .back-button:hover {
            transform: translateX(-4px);
            background: var(--primary-light);
            color: white;
        }

        .form-container {
            background: var(--card-background);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-lg);
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

        .button-group {
            display: flex;
            gap: 12px;
            margin-top: 32px;
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

        .attachments-section {
            background: var(--background-color);
            border-radius: var(--radius-md);
            padding: 16px;
            margin-top: 24px;
        }

        .attachments-list {
            margin-top: 16px;
        }

        .attachment-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: white;
            border-radius: var(--radius-md);
            margin-bottom: 8px;
            box-shadow: var(--shadow-sm);
        }

        .attachment-item:last-child {
            margin-bottom: 0;
        }

        .attachment-name {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-color);
        }

        .delete-attachment {
            color: var(--danger-color);
            cursor: pointer;
            padding: 8px;
            border: none;
            background: none;
            font-size: 1.2em;
            border-radius: var(--radius-sm);
            transition: var(--transition);
        }

        .delete-attachment:hover {
            background: rgba(231, 76, 60, 0.1);
        }

        .file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
        }

        .file-input-wrapper input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            cursor: pointer;
        }

        .file-input-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: var(--primary-color);
            color: white;
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: var(--transition);
        }

        .file-input-button:hover {
            background: var(--primary-dark);
        }

        @media (max-width: 768px) {
            .container {
                margin: 20px;
                padding: 16px;
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

            .button-group {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="manage_tasks.php" class="back-button">
                <i class="fas fa-arrow-left"></i>
                Back to Tasks
            </a>
            <h1><i class="fas fa-edit"></i> Edit Task</h1>
        </div>

        <div class="form-container">
            <form method="POST" action="manage_tasks.php" enctype="multipart/form-data">
                <input type="hidden" name="task_id" value="<?php echo $task_id; ?>">
                
                <div class="form-group">
                    <label>Title</label>
                    <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($task['title']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" class="form-control" required><?php echo htmlspecialchars($task['description']); ?></textarea>
                </div>
                
                <div class="form-group">
                    <label>Assign To</label>
                    <select name="assigned_to" class="form-control" required>
                        <?php 
                        $staff_result->data_seek(0);
                        while ($staff = $staff_result->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $staff['id']; ?>" <?php echo $staff['id'] == $task['assigned_to'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Due Date</label>
                    <input type="datetime-local" name="due_date" class="form-control" 
                           value="<?php echo date('Y-m-d\TH:i', strtotime($task['due_date'])); ?>" required>
                </div>

                <div class="form-group">
                    <label>Priority</label>
                    <select name="priority" class="form-control" required>
                        <option value="low" <?php echo $task['priority'] === 'low' ? 'selected' : ''; ?>>Low</option>
                        <option value="medium" <?php echo $task['priority'] === 'medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="high" <?php echo $task['priority'] === 'high' ? 'selected' : ''; ?>>High</option>
                    </select>
                </div>

                <div class="attachments-section">
                    <label>Attachments</label>
                    <div class="file-input-wrapper">
                        <div class="file-input-button">
                            <i class="fas fa-paperclip"></i>
                            Add New Attachments
                        </div>
                        <input type="file" name="attachments[]" multiple>
                    </div>

                    <?php if ($attachments->num_rows > 0): ?>
                    <div class="attachments-list">
                        <h3>Current Attachments</h3>
                        <?php while ($attachment = $attachments->fetch_assoc()): ?>
                            <div class="attachment-item">
                                <div class="attachment-name">
                                    <i class="fas fa-file"></i>
                                    <?php echo htmlspecialchars($attachment['file_name']); ?>
                                </div>
                                <button type="button" class="delete-attachment" 
                                        onclick="deleteAttachment(<?php echo $attachment['id']; ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        <?php endwhile; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="button-group">
                    <button type="submit" name="edit_task" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <a href="manage_tasks.php" class="btn btn-danger">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script>
    function deleteAttachment(attachmentId) {
        if (confirm('Are you sure you want to delete this attachment?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'manage_tasks.php';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'delete_attachment';
            input.value = attachmentId;
            
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
    }
    </script>
</body>
</html> 