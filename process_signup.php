<?php
$host = "localhost";
$user = "root";    
$pass = "";    
$dbname = "socmedia_db"; 

$conn = new mysqli($host, $user, $pass);

if ($conn->connect_error) {
    die("Connection to database failed: " . $conn->connect_error);
}

$createDB = "CREATE DATABASE IF NOT EXISTS $dbname";
if (!$conn->query($createDB)) {
    die("Database creation failed: " . $conn->error);
}

$conn->select_db($dbname);

$createTableSQL = "
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    User_name VARCHAR(50) NOT NULL UNIQUE,
    Password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($createTableSQL);

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $username = trim($_POST['User_name']);
    $password = trim($_POST['Password']);

    if (empty($username) || empty($password)) {
        die("All fields are required.");
    }

    $check = $conn->prepare("SELECT id FROM users WHERE User_name = ?");
    $check->bind_param("s", $username);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        echo "
        <div class='alert alert-danger text-center' role='alert'>
            Username already exists. Please choose another.
        </div>

        <script>
            setTimeout(function() {
                window.location.href = 'signup.php';
            }, 2000); // 2 seconds
        </script>
        ";
        $check->close();
        exit;
    }
    $check->close();

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO users (User_name, Password) VALUES (?, ?)");
    $stmt->bind_param("ss", $username, $hashedPassword);

    if ($stmt->execute()) {
        echo "
        <div class='alert alert-success text-center' role='alert'>
            Signup successful! Redirecting to login...
        </div>

        <script>
            setTimeout(function() {
                window.location.href = 'login.php';
            }, 2000); // 2 seconds
        </script>
        ";
    } else {
        echo "Error: " . $stmt->error;
    }


    $stmt->close();
}

$conn->close();
?>