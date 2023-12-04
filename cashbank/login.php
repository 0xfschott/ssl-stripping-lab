<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = $_POST['password'];
    if (empty($username) || empty($password)) {
        echo "Username or password is empty";
    } else {
        echo "Logged in as: " . htmlspecialchars($username);
        // Add additional logic here if needed
    }
}
?>