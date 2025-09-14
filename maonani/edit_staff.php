<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
$host = "localhost"; 
$dbname = "accountant"; 
$username = "root"; 
$password = ""; 

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Get user ID from URL
$edit_user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Check permissions
if ($role === 'staff' && $edit_user_id !== $user_id) {
    $_SESSION['error_message'] = "You can only edit your own profile";
    header("Location: manage_staff.php");
    exit();
}

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $edit_user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update_user'])) {
    $update_id = (int)$_POST['user_id'];
    
    // Re-fetch user data for role reference
    $stmt = $conn->prepare("SELECT role, password FROM users WHERE id = ?");
    $stmt->bind_param("i", $update_id);
    $stmt->execute();
    $current_user_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Verify permissions
    if ($role === 'admin' || ($role === 'staff' && $update_id === $user_id)) {
        // Collect form data
        $first_name = $_POST['first_name'];
        $last_name = $_POST['last_name'];
        $middle_name = $_POST['middle_name'];
        $username = $_POST['username'];
        $phone = $_POST['phone'];
        $new_role = isset($_POST['role']) ? $_POST['role'] : $current_user_data['role'];
        
        // Initialize password variables
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Start building query
        $query = "UPDATE users SET 
            first_name = ?, 
            last_name = ?, 
            middle_name = ?, 
            username = ?, 
            phone = ?";
        $types = "sssss";
        $params = [
            $first_name, 
            $last_name, 
            $middle_name, 
            $username, 
            $phone
        ];
        
        // Handle password update
        $password_updated = false;
        if (!empty($current_password) || !empty($new_password) || !empty($confirm_password)) {
            // Verify current password
            if (!password_verify($current_password, $current_user_data['password'])) {
                $_SESSION['error_message'] = "Current password is incorrect";
                header("Location: edit_staff.php?id=".$update_id);
                exit();
            }
            
            if ($new_password !== $confirm_password) {
                $_SESSION['error_message'] = "New passwords don't match";
                header("Location: edit_staff.php?id=".$update_id);
                exit();
            }
            
            if (strlen($new_password) < 6) {
                $_SESSION['error_message'] = "Password must be at least 6 characters";
                header("Location: edit_staff.php?id=".$update_id);
                exit();
            }
            
            $query .= ", password = ?";
            $types .= "s";
            $params[] = password_hash($new_password, PASSWORD_DEFAULT);
            $password_updated = true;
        }
        
        // Add role update for admin
        if ($role === 'admin') {
            $query .= ", role = ?";
            $types .= "s";
            $params[] = $new_role;
        }
        
        // Finalize query
        $query .= " WHERE id = ?";
        $types .= "i";
        $params[] = $update_id;
        
        // Prepare and execute statement
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            die("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            die("Execute failed: " . $stmt->error);
        }
        
        // Check if any rows were affected
        if ($stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Profile updated successfully!";
        } else {
            $_SESSION['error_message'] = "No changes were made";
        }
        $stmt->close();
    }
    
    header("Location: edit_staff.php?id=".$update_id);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User Profile</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4a90e2;
            --secondary-color: #5a6268;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --border-color: #dee2e6;
            --border-radius: 8px;
            --box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f4f7fc;
            color: #333;
            line-height: 1.6;
        }

        .container {
            max-width: 800px;
            margin: 40px auto;
            padding: 0;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding: 0 20px;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--secondary-color);
            color: white;
            padding: 10px 16px;
            text-decoration: none;
            border-radius: var(--border-radius);
            transition: var(--transition);
            font-weight: 500;
        }

        .back-btn:hover {
            background: #444;
            transform: translateY(-2px);
        }

        .page-title {
            font-size: 1.5rem;
            color: var(--dark-color);
            margin: 0;
            text-align: center;
            flex-grow: 1;
        }

        .user-card {
            background: #fff;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }

        .user-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #3178c6 100%);
            color: white;
            padding: 25px;
            text-align: center;
            position: relative;
        }

        .user-avatar {
            width: 100px;
            height: 100px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            border: 4px solid rgba(255, 255, 255, 0.3);
        }

        .user-avatar i {
            font-size: 48px;
            color: var(--primary-color);
        }

        .user-name {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .user-role {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .form-content {
            padding: 30px;
        }

        .form-section {
            margin-bottom: 25px;
        }

        .section-title {
            font-size: 1.1rem;
            color: var(--dark-color);
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-control {
            margin-bottom: 0;
        }

        .form-control label {
            display: block;
            font-weight: 500;
            margin-bottom: 8px;
            color: #555;
            font-size: 0.9rem;
        }

        .form-control input,
        .form-control select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            transition: var(--transition);
            font-family: inherit;
            font-size: 0.95rem;
        }

        .form-control input:focus,
        .form-control select:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
        }

        .form-control select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23555' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            padding-right: 40px;
        }

        .password-group {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #777;
            cursor: pointer;
        }

        .action-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 30px;
        }

        .btn {
            padding: 12px 24px;
            border-radius: var(--border-radius);
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            font-family: inherit;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: #3a7bc8;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: var(--secondary-color);
            color: white;
        }

        .btn-secondary:hover {
            background: #4a5258;
            transform: translateY(-2px);
        }

        .alert {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            position: relative;
            animation: fadeIn 0.3s ease;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-icon {
            margin-right: 10px;
        }

        .alert-close {
            position: absolute;
            right: 15px;
            top: 15px;
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            opacity: 0.7;
        }

        .alert-close:hover {
            opacity: 1;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 768px) {
            .container {
                margin: 20px auto;
            }
            
            .form-group {
                grid-template-columns: 1fr;
            }
            
            .user-avatar {
                width: 80px;
                height: 80px;
            }
            
            .user-avatar i {
                font-size: 36px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <a href="manage_staff.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Users List
            </a>
        </div>
        
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle alert-icon"></i>
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                <button class="alert-close"><i class="fas fa-times"></i></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle alert-icon"></i>
                <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                <button class="alert-close"><i class="fas fa-times"></i></button>
            </div>
        <?php endif; ?>

        <div class="user-card">
            <div class="user-header">
                <div class="user-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <h2 class="user-name"><?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?></h2>
                <span class="user-role"><?php echo ucfirst(htmlspecialchars($user['role'])); ?></span>
            </div>
            
            <form method="POST" class="form-content">
                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-user"></i> Personal Information
                    </h3>
                    <div class="form-group">
                        <div class="form-control">
                            <label for="first_name">First Name</label>
                            <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                        </div>
                        <div class="form-control">
                            <label for="last_name">Last Name</label>
                            <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                        </div>
                        <div class="form-control">
                            <label for="middle_name">Middle Name</label>
                            <input type="text" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($user['middle_name'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-address-card"></i> Contact Information
                    </h3>
                    <div class="form-group">
                        <div class="form-control">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                        </div>
                        <div class="form-control">
                            <label for="phone">Phone Number</label>
                            <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-lock"></i> Security
                    </h3>
                    <div class="form-group">
                        <div class="form-control">
                            <label for="current_password">Current Password</label>
                            <div class="password-group">
                                <input type="password" id="current_password" name="current_password" placeholder="Enter current password to change">
                                <button type="button" class="password-toggle" data-target="current_password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-control">
                            <label for="new_password">New Password</label>
                            <div class="password-group">
                                <input type="password" id="new_password" name="new_password" placeholder="New password">
                                <button type="button" class="password-toggle" data-target="new_password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-control">
                            <label for="confirm_password">Confirm New Password</label>
                            <div class="password-group">
                                <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password">
                                <button type="button" class="password-toggle" data-target="confirm_password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if ($role === 'admin'): ?>
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-user-shield"></i> Role Management
                    </h3>
                    <div class="form-group">
                        <div class="form-control">
                            <label for="role">User Role</label>
                            <select id="role" name="role">
                                <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                <option value="staff" <?php echo $user['role'] === 'staff' ? 'selected' : ''; ?>>Staff</option>
                            </select>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="action-buttons">
                    <button type="submit" name="update_user" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // Password visibility toggle
    document.querySelectorAll('.password-toggle').forEach(button => {
        button.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const passwordInput = document.getElementById(targetId);
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                this.innerHTML = '<i class="fas fa-eye-slash"></i>';
            } else {
                passwordInput.type = 'password';
                this.innerHTML = '<i class="fas fa-eye"></i>';
            }
        });
    });

    // Alert close button
    document.querySelectorAll('.alert-close').forEach(button => {
        button.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });

    // Auto fade out alerts after 5 seconds
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(() => {
                    alert.style.display = 'none';
                }, 300);
            });
        }, 5000);
    });
    </script>
</body>
</html>