<?php
session_start();
require_once 'db_connection.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Fetch all staff members
$query = "SELECT id, first_name, last_name, middle_name, username, phone, role FROM users ORDER BY role, first_name";
$result = $conn->query($query);
$users_count = $result->num_rows;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - InboxSandy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4a90e2;
            --danger-color: #dc3545;
            --success-color: #28a745;
            --background-color: #f8f9fa;
            --card-background: #ffffff;
            --text-color: #333333;
            --border-color: #e0e0e0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--background-color);
            color: var(--text-color);
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background-color: var(--card-background);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .back-button {
            color: var(--primary-color);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .search-container {
            position: relative;
            max-width: 500px;
            margin: 0 auto;
        }

        .search-input {
            width: 100%;
            padding: 12px 20px;
            border: 2px solid var(--border-color);
            border-radius: 25px;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
        }

        .search-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
        }

        .staff-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .staff-card {
            background: var(--card-background);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }

        .staff-card:hover {
            transform: translateY(-5px);
        }

        .profile-image {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 15px;
            display: block;
            border: 3px solid var(--primary-color);
        }

        .staff-info {
            text-align: center;
            margin-bottom: 20px;
        }

        .staff-name {
            font-size: 1.2em;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .staff-email {
            color: #666;
            font-size: 0.9em;
            margin-bottom: 5px;
        }

        .staff-phone {
            color: #666;
            font-size: 0.9em;
            margin-bottom: 5px;
        }

        .staff-actions {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 15px;
        }

        .btn {
            padding: 8px 15px;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            font-size: 0.9em;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .btn-edit {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-delete {
            background-color: var(--danger-color);
            color: white;
        }

        .btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .btn-add {
            background-color: var(--success-color);
            color: white;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }

        .btn-add:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }

        .message {
            padding: 15px 20px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .message-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .message i {
            font-size: 1.2em;
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }

            .staff-grid {
                grid-template-columns: 1fr;
            }

            .header-top {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="message message-success">
                <i class="fas fa-check-circle"></i>
                <?php 
                echo htmlspecialchars($_SESSION['success_message']);
                unset($_SESSION['success_message']);
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="message message-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php 
                echo htmlspecialchars($_SESSION['error_message']);
                unset($_SESSION['error_message']);
                ?>
            </div>
        <?php endif; ?>

        <div class="header">
            <div class="header-top">
                <a href="dashboard.php" class="back-button">
                    <i class="fas fa-arrow-left"></i>
                    Back to Dashboard
                </a>
                <h1>Manage Users (<?php echo $users_count; ?>)</h1>
                <a href="register.php" class="btn-add">
                    <i class="fas fa-plus"></i>
                    Add User
                </a>
            </div>
            <div class="search-container">
                <input type="text" id="searchStaff" class="search-input" placeholder="Search users by name or username...">
                <i class="fas fa-search search-icon"></i>
            </div>
        </div>

        <div class="staff-grid">
            <?php
            if ($users_count > 0) {
                while ($staff = $result->fetch_assoc()) {
                    ?>
                    <div class="staff-card" data-name="<?php echo strtolower($staff['first_name'] . ' ' . $staff['last_name']); ?>" 
                         data-username="<?php echo strtolower($staff['username']); ?>">
                        <img src="assets/default-avatar.png" alt="Profile" class="profile-image">
                        <div class="staff-info">
                            <div class="staff-name">
                                <?php 
                                echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']);
                                if (!empty($staff['middle_name'])) {
                                    echo ' ' . htmlspecialchars($staff['middle_name']);
                                }
                                ?>
                            </div>
                            <div class="staff-email"><?php echo htmlspecialchars($staff['username']); ?></div>
                            <?php if (!empty($staff['phone'])): ?>
                            <div class="staff-phone">
                                <i class="fas fa-phone"></i> <?php echo htmlspecialchars($staff['phone']); ?>
                            </div>
                            <?php endif; ?>
                            <div class="staff-role">
                                Role: <?php echo htmlspecialchars($staff['role']); ?>
                            </div>
                        </div>
                        <div class="staff-actions">
                            <a href="edit_staff.php?id=<?php echo $staff['id']; ?>" class="btn btn-edit">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <?php if($staff['role'] !== 'admin'): ?>
                            <button class="btn btn-delete" onclick="deleteStaff(<?php echo $staff['id']; ?>)">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php
                }
            } else {
                echo '<div class="empty-state">No users found. Add users to get started.</div>';
            }
            ?>
        </div>
    </div>

    <script>
        // Search functionality
        document.getElementById('searchStaff').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const staffCards = document.querySelectorAll('.staff-card');

            staffCards.forEach(card => {
                const name = card.dataset.name;
                const username = card.dataset.username;
                
                if (name.includes(searchTerm) || username.includes(searchTerm)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        // Delete confirmation
        function deleteStaff(staffId) {
            if (confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
                window.location.href = `delete_staff.php?id=${staffId}`;
            }
        }
    </script>
</body>
</html>