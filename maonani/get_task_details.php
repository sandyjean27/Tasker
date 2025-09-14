<?php
session_start();
header('Content-Type: text/html; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo "Unauthorized access";
    exit();
}

if (!isset($_GET['task_id'])) {
    echo "No task ID provided";
    exit();
}

$task_id = (int)$_GET['task_id'];

// Database connection
$host = "localhost";
$dbname = "accountant";
$db_username = "root";
$db_password = "";

$conn = new mysqli($host, $db_username, $db_password, $dbname);

if ($conn->connect_error) {
    echo "Database connection failed";
    exit();
}

// Get task details with user information
$query = "SELECT t.*, 
          u1.first_name as creator_first_name, u1.last_name as creator_last_name,
          u2.first_name as assigned_first_name, u2.last_name as assigned_last_name,
          GROUP_CONCAT(DISTINCT ta.file_name ORDER BY ta.id SEPARATOR '||') as attachment_names,
          GROUP_CONCAT(DISTINCT ta.file_path ORDER BY ta.id SEPARATOR '||') as attachment_paths
          FROM tasks t
          LEFT JOIN users u1 ON t.created_by = u1.id
          LEFT JOIN users u2 ON t.assigned_to = u2.id
          LEFT JOIN task_attachments ta ON t.id = ta.task_id
          WHERE t.id = ?
          GROUP BY t.id";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $task_id);
$stmt->execute();
$result = $stmt->get_result();
$task = $result->fetch_assoc();

if (!$task) {
    echo "Task not found";
    exit();
}

// Format the task details HTML
?>
<div class="task-details-container">
    <h3><?php echo htmlspecialchars($task['title']); ?></h3>
    
    <div class="task-info">
        <p class="description"><?php echo nl2br(htmlspecialchars($task['description'])); ?></p>
        
        <div class="task-metadata">
            <p><strong>Status:</strong> <?php echo ucfirst($task['status']); ?></p>
            <p><strong>Due Date:</strong> <?php echo date('M j, Y', strtotime($task['due_date'])); ?></p>
            <p><strong>Priority:</strong> <?php echo ucfirst($task['priority']); ?></p>
            <p><strong>Created by:</strong> <?php echo htmlspecialchars($task['creator_first_name'] . ' ' . $task['creator_last_name']); ?></p>
            <p><strong>Assigned to:</strong> <?php echo htmlspecialchars($task['assigned_first_name'] . ' ' . $task['assigned_last_name']); ?></p>
            
            <?php if ($task['status'] === 'completed'): ?>
                <p><strong>Completed at:</strong> <?php echo date('M j, Y g:i A', strtotime($task['completed_at'])); ?></p>
                <?php if ($task['completion_notes']): ?>
                    <p><strong>Completion Notes:</strong> <?php echo nl2br(htmlspecialchars($task['completion_notes'])); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($task['attachment_names']): ?>
            <div class="task-attachments">
                <h4>Attachments:</h4>
                <ul>
                    <?php
                    $names = explode('||', $task['attachment_names']);
                    $paths = explode('||', $task['attachment_paths']);
                    for ($i = 0; $i < count($names); $i++): ?>
                        <li>
                            <a href="uploads/<?php echo htmlspecialchars($paths[$i]); ?>" target="_blank">
                                <i class="fas fa-paperclip"></i> <?php echo htmlspecialchars($names[$i]); ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.task-details-container {
    padding: 20px;
}

.task-info {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-top: 15px;
}

.task-info .description {
    margin-bottom: 20px;
    line-height: 1.5;
}

.task-metadata {
    border-top: 1px solid #dee2e6;
    padding-top: 15px;
    margin-top: 15px;
}

.task-metadata p {
    margin: 8px 0;
}

.task-attachments {
    margin-top: 20px;
    border-top: 1px solid #dee2e6;
    padding-top: 15px;
}

.task-attachments ul {
    list-style: none;
    padding: 0;
    margin: 10px 0;
}

.task-attachments li {
    margin: 5px 0;
}

.task-attachments a {
    color: #007bff;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.task-attachments a:hover {
    text-decoration: underline;
}
</style>
