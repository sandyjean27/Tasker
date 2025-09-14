<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Task</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background: url('image.png') no-repeat center center fixed;
            background-size: cover;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .modal {
            display: flex;
            flex-direction: column;
            background: rgba(255, 255, 255, 0.9);
            padding: 30px;
            border-radius: 10px;
            box-shadow: 2px 2px 10px rgba(0, 0, 0, 0.1);
            width: 700px;
        }
        .section {
            display: flex;
            flex-direction: column;
            margin-bottom: 20px;
        }
        .input-field {
            width: 100%;
            padding: 12px;
            margin-top: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        .buttons {
            display: flex;
            justify-content: space-between;
        }
        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .btn-cancel {
            background: red;
            color: white;
        }
        .btn-add {
            background: black;
            color: white;
        }
        .btn-attach {
            background: gray;
            color: white;
        }
        .back-icon {
            position: absolute;
            top: 20px;
            left: 20px;
            font-size: 24px;
            cursor: pointer;
            color: white;
        }
    </style>
</head>
<body>
    <i class="fas fa-arrow-left back-icon" onclick="window.location.href='dashboard.php'"></i>
    <div class="modal">
        <div class="section">
            <input type="text" class="input-field" id="taskTitle" placeholder="Title">
            <textarea class="input-field" id="taskDescription" placeholder="Description" rows="4"></textarea>
        </div>
        <div class="section">
            <input type="text" class="input-field" id="assignTo" placeholder="Assign to">
            <input type="date" class="input-field" id="taskDate">
            <input type="time" class="input-field" id="taskTime">
            <input type="file" class="input-field" id="taskAttachment">
        </div>
        <div class="buttons">
            <button class="btn btn-cancel" onclick="window.location.href='dashboard.php'">Cancel</button>
            <button class="btn btn-attach" onclick="attachFile()">Attach</button>
            <button class="btn btn-add" onclick="saveTask()">Add</button>
        </div>
    </div>
    <script>
    function attachFile() {
        let fileInput = document.getElementById("taskAttachment");
        if (fileInput.files.length > 0) {
            alert("File attached: " + fileInput.files[0].name);
        } else {
            alert("No file selected.");
        }
    }

    function saveTask() {
        let title = document.getElementById("taskTitle").value;
        let description = document.getElementById("taskDescription").value;
        let date = document.getElementById("taskDate").value;
        let time = document.getElementById("taskTime").value;
        let assignTo = document.getElementById("assignTo").value;
        
        if (title && date && time && assignTo) {
            let pendingTasks = JSON.parse(localStorage.getItem("pendingTasks")) || [];
            pendingTasks.push({ title, description, date, time, assignTo });
            localStorage.setItem("pendingTasks", JSON.stringify(pendingTasks));
            alert("Task Scheduled: " + title + " on " + date + " at " + time + " assigned to " + assignTo);
            window.location.href = 'dashboard.php';
        } else {
            alert("Please fill in all fields.");
        }
    }

    function loadPendingTasks() {
        let pendingTasks = JSON.parse(localStorage.getItem("pendingTasks")) || [];
        let pendingList = document.getElementById("pendingList");
        pendingList.innerHTML = "";
        pendingTasks.forEach(task => {
            let taskItem = document.createElement("li");
            taskItem.innerHTML = `<input type='checkbox' onchange='moveTask(this)'> <strong>${task.title}</strong> - ${task.date} ${task.time}`;
            pendingList.appendChild(taskItem);
        });
    }

    document.addEventListener("DOMContentLoaded", loadPendingTasks);
    </script>
</body>
</html>
