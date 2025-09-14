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

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $user_name = $conn->real_escape_string($_POST['username']);
    $input_password = $conn->real_escape_string($_POST['password']);

    $query = "SELECT * FROM users WHERE username = ? AND password = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $user_name, $input_password);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        header("Location: dashboard.php");
        exit();
    } else {
        $error = "Invalid username or password!";
    }

    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="stylesheet" href="styles.css">
</head>
<style>
    }

.container {
    max-width: 600px;
    width: 100%;
    background-color: #ffffff; 
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    text-align: center;
}

form {
    display: flex;
    flex-direction: column;
    gap: 15px;
    align-items: flex-start; 
}

form label {
    font-size: 16px;
    color: #000000;
    margin-bottom: 5px;
    text-align: left;
    width: 100%;
}

form input[type="text"],
form input[type="password"] {
    padding: 10px;
    border: 1px solid #000000; 
    border-radius: 25px;
    font-size: 16px;
    outline: none;
    width: 96.7%;
}

form input[type="text"]:focus,
form input[type="password"]:focus {
    border-color: #ffffff; 
    box-shadow: 0 0 8px rgba(255, 255, 255, 0.6);
}

form button {
    background-color: #000000;
    color: #fff;
    padding: 14px;
    border: none;
    border-radius: 30px;
    font-size: 18px;
    cursor: pointer;
    width: 100%;
}

form button:hover {
    background-color: #000000;
}

h2 {
    font-size: 32px;
    color: #000000;
    margin-bottom: 25px;
}

section p {
    font-size: 16px;
    margin-top: 15px;
}

section p a {
    color: #000000;
    text-decoration: none;
    font-size: 16px;
    margin-top: 15px;
    display: inline-block;
}

section p a:hover {
    text-decoration: underline;
}

p.error {
    color: #0a0a0a;
    text-align: center;
    font-size: 18px;
    margin-bottom: 15px;
}
</style>
<body>

<section class="container">
    <h2>Login</h2>
    
    <?php
    if (isset($error)) {
        echo "<p class='error'>$error</p>";
    }
    ?>

    <form action="" method="POST">
        <label>Username</label>
        <input type="text" name="username" required>

        <label>Password</label>
        <input type="password" name="password" required>

        <button type="submit">Login</button>
    </form>
</section>

</body>
</html>
