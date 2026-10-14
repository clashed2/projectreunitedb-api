<?php
ob_start();
require_once 'db.php';

$content = file_get_contents("php://input");
$data = json_decode($content, true);
if (is_null($data)) { $data = $_POST; }

if (!empty($data['name']) && !empty($data['username']) && !empty($data['email']) && !empty($data['password'])) {
    $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);

    try {
        // Matches your schema: name, username, password, email, phone_number
        $stmt = $pdo->prepare("INSERT INTO users (name, username, email, password, phone_number) VALUES (:name, :username, :email, :pass, :phone)");
        $stmt->execute([
            'name'     => $data['name'],
            'username' => $data['username'],
            'email'    => $data['email'],
            'pass'     => $hashedPassword,
            'phone'    => $data['phone_number'] ?? null
        ]);
        ob_clean();
        echo json_encode(["status" => "success", "message" => "Account created successfully"]);
        exit();
    } catch (PDOException $e) {
        ob_clean();
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Registration failed: " . $e->getMessage()]);
        exit();
    }
} else {
    ob_clean();
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Incomplete details"]);
    exit();
}