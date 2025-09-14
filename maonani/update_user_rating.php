<?php
require_once 'db_connection.php';

/**
 * Update a user's performance rating based on task completion metrics
 * 
 * @param int $user_id The ID of the user to update
 * @param mysqli $conn Database connection
 * @return float|bool The new rating or false on failure
 */
function updateUserRating($user_id, $conn) {
    // Get current date and date 30 days ago
    $current_date = date('Y-m-d');
    $month_ago = date('Y-m-d', strtotime('-30 days'));
    
    // Get total tasks assigned to user in last 30 days
    $total_query = "SELECT COUNT(*) as total FROM tasks 
                   WHERE assigned_to = ? 
                   AND DATE(created_at) BETWEEN ? AND ?";
    $stmt = $conn->prepare($total_query);
    $stmt->bind_param("iss", $user_id, $month_ago, $current_date);
    $stmt->execute();
    $total_result = $stmt->get_result()->fetch_assoc();
    $total_tasks = $total_result['total'];
    
    // If no tasks, don't update rating
    if ($total_tasks == 0) {
        return false;
    }
    
    // Get completed tasks
    $completed_query = "SELECT COUNT(*) as completed FROM tasks 
                       WHERE assigned_to = ? 
                       AND status = 'completed' 
                       AND DATE(created_at) BETWEEN ? AND ?";
    $stmt = $conn->prepare($completed_query);
    $stmt->bind_param("iss", $user_id, $month_ago, $current_date);
    $stmt->execute();
    $completed_result = $stmt->get_result()->fetch_assoc();
    $completed_tasks = $completed_result['completed'];
    
    // Get overdue tasks
    $overdue_query = "SELECT COUNT(*) as overdue FROM tasks 
                     WHERE assigned_to = ? 
                     AND status = 'pending' 
                     AND due_date < CURDATE() 
                     AND DATE(created_at) BETWEEN ? AND ?";
    $stmt = $conn->prepare($overdue_query);
    $stmt->bind_param("iss", $user_id, $month_ago, $current_date);
    $stmt->execute();
    $overdue_result = $stmt->get_result()->fetch_assoc();
    $overdue_tasks = $overdue_result['overdue'];
    
    // Get average completion time (in hours)
    $time_query = "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, completed_at)) as avg_time 
                  FROM tasks 
                  WHERE assigned_to = ? 
                  AND status = 'completed' 
                  AND completed_at IS NOT NULL 
                  AND DATE(created_at) BETWEEN ? AND ?";
    $stmt = $conn->prepare($time_query);
    $stmt->bind_param("iss", $user_id, $month_ago, $current_date);
    $stmt->execute();
    $time_result = $stmt->get_result()->fetch_assoc();
    $avg_completion_time = $time_result['avg_time'] ? $time_result['avg_time'] : 0;
    
    // Get task resubmissions
    $resubmission_query = "SELECT COUNT(*) as resubmissions FROM task_submissions 
                          WHERE submitted_by = ? 
                          AND status = 'rejected' 
                          AND DATE(submitted_at) BETWEEN ? AND ?";
    $stmt = $conn->prepare($resubmission_query);
    $stmt->bind_param("iss", $user_id, $month_ago, $current_date);
    $stmt->execute();
    $resubmission_result = $stmt->get_result()->fetch_assoc();
    $resubmissions = $resubmission_result['resubmissions'];
    
    // Calculate completion rate (0-100%)
    $completion_rate = ($total_tasks > 0) ? ($completed_tasks / $total_tasks) * 100 : 0;
    
    // Calculate overdue rate (0-100%)
    $overdue_rate = ($total_tasks > 0) ? ($overdue_tasks / $total_tasks) * 100 : 0;
    
    // Calculate resubmission rate (0-100%)
    $resubmission_rate = ($total_tasks > 0) ? ($resubmissions / $total_tasks) * 100 : 0;
    
    // Calculate time efficiency score (0-100%)
    // Assuming 72 hours (3 days) is the expected completion time
    $expected_time = 72;
    $time_efficiency = ($avg_completion_time > 0) ? 
                      max(0, min(100, 100 - (($avg_completion_time - $expected_time) / $expected_time * 100))) : 
                      100;
    
    // Calculate overall performance score (0-5 scale)
    $performance_score = 0;
    
    // Completion rate contributes 40% to the score (0-2 points)
    $performance_score += ($completion_rate / 100) * 2;
    
    // Overdue rate contributes 25% to the score (0-1.25 points, inversely)
    $performance_score += (1 - ($overdue_rate / 100)) * 1.25;
    
    // Resubmission rate contributes 15% to the score (0-0.75 points, inversely)
    $performance_score += (1 - ($resubmission_rate / 100)) * 0.75;
    
    // Time efficiency contributes 20% to the score (0-1 point)
    $performance_score += ($time_efficiency / 100) * 1;
    
    // Round to 2 decimal places
    $performance_score = round($performance_score, 2);
    
    // Update user's rating in the database
    $update_query = "INSERT INTO user_ratings (user_id, rating) VALUES (?, ?) 
                    ON DUPLICATE KEY UPDATE rating = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("idd", $user_id, $performance_score, $performance_score);
    
    if ($stmt->execute()) {
        return $performance_score;
    } else {
        return false;
    }
}

/**
 * Schedule automatic rating updates for all staff users
 * This function should be called periodically (e.g., daily via cron job)
 */
function updateAllUserRatings($conn) {
    // Get all staff users
    $get_staff = "SELECT id FROM users WHERE role = 'staff'";
    $staff_result = $conn->query($get_staff);
    
    $updated_count = 0;
    
    if ($staff_result->num_rows > 0) {
        while($row = $staff_result->fetch_assoc()) {
            $user_id = $row['id'];
            $new_rating = updateUserRating($user_id, $conn);
            
            if ($new_rating !== false) {
                $updated_count++;
            }
        }
    }
    
    return $updated_count;
}

// If this script is called directly, update all ratings
if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    $updated = updateAllUserRatings($conn);
    echo "Updated ratings for $updated users.";
}
?>
