<?php
session_start();

$host = "localhost";
$user = "root";
$pass = "";
$dbname = "socmedia_db";

$conn = new mysqli($host, $user, $pass);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->select_db($dbname);

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $username = trim($_POST['User_name']);
    $password = trim($_POST['Password']);

    if (empty($username) || empty($password)) {
        echo "
        <div class='alert alert-danger text-center' role='alert'>
            Please enter both username and password.
        </div>
        ";
        exit;
    }

    $stmt = $conn->prepare("SELECT id, Password FROM users WHERE User_name = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows == 0) {
        echo "
        <div class='alert alert-danger text-center' role='alert'>
            Invalid username or password.
        </div>
        ";
        exit;
    }

    $stmt->bind_result($user_id, $hashedPassword);
    $stmt->fetch();

    if (password_verify($password, $hashedPassword)) {

        $_SESSION['user_id'] = $user_id;
        $_SESSION['username'] = $username;

        echo "
        <div class='alert alert-success text-center' role='alert'>
            Login successful! Redirecting to dashboard...
        </div>

        <script>
            setTimeout(function() {
                window.location.href = 'dashboard.php';
            }, 2000);
        </script>
        ";
    } else {
        echo "
        <div class='alert alert-danger text-center' role='alert'>
            Invalid username or password.
        </div>
        ";
    }

    $stmt->close();
}

$conn->close();
?>