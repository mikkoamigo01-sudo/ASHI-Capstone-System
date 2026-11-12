<?php
// Handle request first
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header("Content-Type: application/json");

    $conn = new mysqli("localhost", "root", "", "users");

    if ($conn->connect_error) {
        echo json_encode(["message" => "Database connection failed"]);
        exit;
    }

    $data = json_decode(file_get_contents("php://input"), true);

    $username = trim($data["username"] ?? "");
    $password = trim($data["password"] ?? "");

    if (!$username || !$password) {
        echo json_encode(["message" => "All fields are required"]);
        exit;
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO admins (username, password) VALUES (?, ?)");
    $stmt->bind_param("ss", $username, $hashedPassword);

    if ($stmt->execute()) {
        echo json_encode(["message" => "Admin registered successfully"]);
    } else {
        echo json_encode(["message" => "Username already exists"]);
    }

    $stmt->close();
    $conn->close();
    exit; // stop HTML from loading on POST
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Register</title>
</head>
<body>

<h2>Register Admin</h2>

<form id="registerForm">
    <input type="text" id="username" placeholder="Username" required><br><br>
    <input type="password" id="password" placeholder="Password" required><br><br>
    <button type="submit">Register</button>
</form>

<p id="message"></p>

<script>
document.getElementById("registerForm").addEventListener("submit", function(e) {
    e.preventDefault();

    fetch("", { // same file
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify({
            username: document.getElementById("username").value,
            password: document.getElementById("password").value
        })
    })
    .then(res => res.json())
    .then(data => {
        document.getElementById("message").innerText = data.message;
    });
});
</script>

</body>
</html>