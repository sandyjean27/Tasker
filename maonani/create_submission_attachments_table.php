<?php
require_once 'db_connection.php';

// Create submission_attachments table
$create_table_sql = "CREATE TABLE IF NOT EXISTS submission_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (submission_id) REFERENCES task_submissions(id) ON DELETE CASCADE
)";

if ($conn->query($create_table_sql)) {
    echo "submission_attachments table created successfully!";
} else {
    echo "Error creating table: " . $conn->error;
}
?>
