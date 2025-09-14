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

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $user_id = (int)$_POST['user_id'];
    $new_password = $_POST['new_password'];
    
    if (empty($new_password)) {
        $error = "Password cannot be empty!";
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hashed_password, $user_id);
        
        if ($stmt->execute()) {
            $success = "Password has been reset successfully!";
        } else {
            $error = "Error resetting password: " . $stmt->error;
        }
        
        $stmt->close();
    }
}

// Get all users
$users_query = "SELECT id, username, first_name, last_name FROM users ORDER BY username";
$users_result = $conn->query($users_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>

<section class="container">
    <h2>Reset User Password</h2>
    
    <?php
    if (isset($error)) {
        echo "<p class='error'>$error</p>";
    }
    if (isset($success)) {
        echo "<p class='success'>$success</p>";
    }
    ?>

    <form action="" method="POST">
        <label>Select User</label>
        <select name="user_id" required>
            <?php while ($user = $users_result->fetch_assoc()): ?>
                <option value="<?php echo $user['id']; ?>">
                    <?php echo htmlspecialchars($user['username'] . ' (' . $user['first_name'] . ' ' . $user['last_name'] . ')'); ?>
                </option>
            <?php endwhile; ?>
        </select>

        <label>New Password</label>
        <input type="password" name="new_password" required>

        <button type="submit">Reset Password</button>
    </form>
    <p><a href="dashboard.php">Back to Dashboard</a></p>
</section>

</body>
</html> 