<?php
ob_start();
require_once 'db.php';

$content = file_get_contents("php://input");
$data = json_decode($content, true);
if (is_null($data)) { $data = $_POST; }

$username = $data['username'] ?? '';
$password = $data['password'] ?? '';

if (!empty($username) && !empty($password)) {
    try {
        // Use two different placeholders :u1 and :u2
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :u1 OR email = :u2 LIMIT 1");
        $stmt->execute(['u1' => $username, 'u2' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            unset($user['password']);
            ob_clean();
            echo json_encode(["status" => "success", "user" => $user]);
            exit();
        } else {
            ob_clean();
            http_response_code(401);
            echo json_encode(["status" => "error", "message" => "Invalid username or password"]);
            exit();
        }
    } catch (PDOException $e) {
        ob_clean();
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "DB Error: " . $e->getMessage()]);
        exit();
    }
} else {
    ob_clean();
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Username and password required"]);
    exit();
}