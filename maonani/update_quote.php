<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die(json_encode(['success' => false, 'error' => 'Unauthorized']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quote'])) {
    $host = "localhost";
    $dbname = "accountant";
    $db_username = "root";
    $db_password = "";

    $conn = new mysqli($host, $db_username, $db_password, $dbname);

    if ($conn->connect_error) {
        die(json_encode(['success' => false, 'error' => 'Database connection failed']));
    }

    $quote = $conn->real_escape_string($_POST['quote']);
    
    // Update or insert the quote
    $sql = "INSERT INTO settings (name, value) 
            VALUES ('motd_quote', ?) 
            ON DUPLICATE KEY UPDATE value = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $quote, $quote);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }

    $stmt->close();
    $conn->close();
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
}
?>