<?php
require_once 'db_connection.php';

// Create user_ratings table
$create_ratings_table = "CREATE TABLE IF NOT EXISTS user_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    rating DECIMAL(3,2) NOT NULL DEFAULT 3.00,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)";

if ($conn->query($create_ratings_table) === TRUE) {
    echo "User ratings table created successfully<br>";
} else {
    echo "Error creating user ratings table: " . $conn->error . "<br>";
}

// Add initial ratings for all staff users
$get_staff = "SELECT id FROM users WHERE role = 'staff'";
$staff_result = $conn->query($get_staff);

if ($staff_result->num_rows > 0) {
    while($row = $staff_result->fetch_assoc()) {
        $user_id = $row['id'];
        
        // Check if rating already exists
        $check_rating = "SELECT id FROM user_ratings WHERE user_id = $user_id";
        $rating_result = $conn->query($check_rating);
        
        if ($rating_result->num_rows == 0) {
            // Insert default rating
            $insert_rating = "INSERT INTO user_ratings (user_id, rating) VALUES ($user_id, 3.00)";
            if ($conn->query($insert_rating) === TRUE) {
                echo "Default rating added for user ID: $user_id<br>";
            } else {
                echo "Error adding default rating for user ID: $user_id - " . $conn->error . "<br>";
            }
        }
    }
} else {
    echo "No staff users found<br>";
}

echo "Setup completed";
?>
