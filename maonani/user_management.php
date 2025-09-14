<?php
session_start();

// Database connection
$host = "localhost"; 
$dbname = "accountant"; 
$username = "root"; 
$password = ""; 

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Fetch user details with additional information
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("
    SELECT 
        u.id, 
        u.first_name, 
        u.last_name, 
        u.middle_name, 
        u.username, 
        u.role, 
        u.phone,
        COUNT(DISTINCT t.id) as total_tasks,
        SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to
    WHERE u.id = ?
    GROUP BY u.id
");

if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
    } else {
        $user = null;
    }
    $stmt->close();
} else {
    die("Query failed: " . $conn->error);
}

// Calculate task completion rate
$completion_rate = 0;
if ($user && $user['total_tasks'] > 0) {
    $completion_rate = ($user['completed_tasks'] / $user['total_tasks']) * 100;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Profile | <?php echo $user ? htmlspecialchars($user['first_name']) : 'Not Found'; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #2b2d42;
            --secondary-color: #8d99ae;
            --accent-color: #ef233c;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --text-color: #333;
            --light-text: #666;
            --white: #fff;
            --gradient: linear-gradient(135deg, #2b2d42 0%, #1a1b2e 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f4f6f9;
            color: var(--text-color);
            line-height: 1.6;
        }

        .page-container {
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 2rem;
        }

        .profile-container {
            background: var(--white);
            width: 100%;
            max-width: 1000px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .profile-header {
            background: var(--gradient);
            padding: 3rem 2rem;
            color: var(--white);
            text-align: center;
            position: relative;
        }

        .profile-header::after {
            content: '';
            position: absolute;
            bottom: -50px;
            left: 0;
            right: 0;
            height: 50px;
            background: var(--gradient);
            clip-path: polygon(0 0, 100% 0, 50% 100%);
        }

        .profile-avatar {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background: var(--white);
            padding: 5px;
            margin: 0 auto 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 4rem;
            color: var(--primary-color);
        }

        .profile-name {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .profile-role {
            display: inline-block;
            padding: 0.5rem 1.5rem;
            border-radius: 25px;
            font-size: 0.9rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .role-admin {
            background: var(--success-color);
            color: var(--white);
        }

        .role-staff {
            background: var(--warning-color);
            color: var(--text-color);
        }

        .profile-content {
            padding: 3rem 2rem;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .info-card {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 15px;
            transition: transform 0.3s ease;
        }

        .info-card:hover {
            transform: translateY(-5px);
        }

        .info-card-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .info-card i {
            font-size: 1.5rem;
            color: var(--primary-color);
        }

        .info-card-title {
            font-size: 0.9rem;
            color: var(--light-text);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .info-card-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-color);
        }

        .stats-container {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .stats-header {
            margin-bottom: 1.5rem;
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .progress-container {
            width: 100%;
            height: 10px;
            background: #e9ecef;
            border-radius: 5px;
            margin: 1rem 0;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: var(--success-color);
            border-radius: 5px;
            transition: width 0.5s ease;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: var(--primary-color);
            color: var(--white);
        }

        .btn-secondary {
            background: var(--secondary-color);
            color: var(--white);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        @media (max-width: 768px) {
            .page-container {
                padding: 1rem;
            }

            .profile-header {
                padding: 2rem 1rem;
            }

            .profile-avatar {
                width: 120px;
                height: 120px;
                font-size: 3rem;
            }

            .profile-name {
                font-size: 1.5rem;
            }

            .info-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="page-container">
        <div class="profile-container">
            <div class="profile-header">
                <div class="profile-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <h1 class="profile-name">
                    <?php 
                    if ($user) {
                        $name = htmlspecialchars($user['first_name']);
                        if (!empty($user['middle_name'])) {
                            $name .= ' ' . htmlspecialchars(substr($user['middle_name'], 0, 1)) . '.';
                        }
                        $name .= ' ' . htmlspecialchars($user['last_name']);
                        echo $name;
                    } else {
                        echo "User not found";
                    }
                    ?>
                </h1>
                <span class="profile-role role-<?php echo $user ? strtolower($user['role']) : ''; ?>">
                    <?php echo $user ? ucfirst($user['role']) : 'N/A'; ?>
                </span>
            </div>

            <div class="profile-content">
                <div class="info-grid">
                    <div class="info-card">
                        <div class="info-card-header">
                            <i class="fas fa-user"></i>
                            <div>
                                <div class="info-card-title">Username</div>
                                <div class="info-card-value"><?php echo $user ? htmlspecialchars($user['username']) : 'N/A'; ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="info-card-header">
                            <i class="fas fa-tasks"></i>
                            <div>
                                <div class="info-card-title">Total Tasks</div>
                                <div class="info-card-value"><?php echo $user ? $user['total_tasks'] : '0'; ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="info-card-header">
                            <i class="fas fa-check-circle"></i>
                            <div>
                                <div class="info-card-title">Completed Tasks</div>
                                <div class="info-card-value"><?php echo $user ? $user['completed_tasks'] : '0'; ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="info-card-header">
                            <i class="fas fa-phone"></i>
                            <div>
                                <div class="info-card-title">Phone Number</div>
                                <div class="info-card-value"><?php echo $user ? htmlspecialchars($user['phone']) : 'N/A'; ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="info-card-header">
                            <i class="fas fa-user-shield"></i>
                            <div>
                                <div class="info-card-title">Role</div>
                                <div class="info-card-value"><?php echo $user ? ucfirst(htmlspecialchars($user['role'])) : 'N/A'; ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="stats-container">
                    <div class="stats-header">
                        <i class="fas fa-chart-bar"></i> Task Performance
                    </div>
                    <div class="progress-container">
                        <div class="progress-bar" style="width: <?php echo $completion_rate; ?>%"></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; color: var(--light-text);">
                        <span>Completion Rate: <?php echo number_format($completion_rate, 1); ?>%</span>
                    </div>
                </div>

                <div class="action-buttons">
                    <a href="dashboard.php" class="btn btn-primary">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <a href="edit_profile.php" class="btn btn-secondary">
                        <i class="fas fa-edit"></i> Edit Profile
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
