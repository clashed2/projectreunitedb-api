<?php
ob_start();
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // View all users or a single user by ?id=
        ob_clean();
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT id, name, username, email, phone_number FROM users WHERE id = :id");
            $stmt->execute(['id' => $_GET['id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                echo json_encode(["status" => "success", "data" => $user]);
            } else {
                http_response_code(404);
                echo json_encode(["status" => "error", "message" => "User not found"]);
            }
        } else {
            $stmt = $pdo->query("SELECT id, name, username, email, phone_number FROM users ORDER BY id DESC");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["status" => "success", "data" => $users]);
        }
        exit();
        break;

    case 'POST':
        // Create a new user account
        $content = file_get_contents("php://input");
        $data = json_decode($content, true);
        if (is_null($data)) { $data = $_POST; }

        if (empty($data['name']) || empty($data['username']) || empty($data['email']) || empty($data['password'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Incomplete details"]);
            exit();
        }

        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("INSERT INTO users (name, username, email, password, phone_number) VALUES (:name, :username, :email, :pass, :phone)");
            $stmt->execute([
                'name'     => $data['name'],
                'username' => $data['username'],
                'email'    => $data['email'],
                'pass'     => $hashedPassword,
                'phone'    => $data['phone_number'] ?? null
            ]);
            ob_clean();
            echo json_encode(["status" => "success", "message" => "User created successfully"]);
            exit();
        } catch (PDOException $e) {
            ob_clean();
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "User creation failed: " . $e->getMessage()]);
            exit();
        }
        break;

    case 'PATCH':
        // Update user profile details
        ob_clean();
        $id = $_GET['id'] ?? null;
        $content = file_get_contents("php://input");
        $data = json_decode($content, true);
        if (!$id && isset($data['id'])) { $id = $data['id']; }

        if (!$id) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Missing user ID for update"]);
            exit();
        }

        try {
            $name = $data['name'] ?? null;
            $phone = $data['phone_number'] ?? null;

            if ($name || $phone) {
                $stmt = $pdo->prepare("UPDATE users SET name = COALESCE(:name, name), phone_number = COALESCE(:phone, phone_number) WHERE id = :id");
                $stmt->execute([
                    'name' => $name,
                    'phone' => $phone,
                    'id' => $id
                ]);
                echo json_encode(["status" => "success", "message" => "User updated successfully"]);
            } else {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "No update fields provided"]);
            }
            exit();
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Database Error: " . $e->getMessage()]);
            exit();
        }
        break;

    case 'DELETE':
        // Delete a user
        ob_clean();
        $id = $_GET['id'] ?? null;
        if (!$id) {
            $content = file_get_contents("php://input");
            $data = json_decode($content, true);
            $id = $data['id'] ?? null;
        }

        if (!$id) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Missing user ID for deletion"]);
            exit();
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute(['id' => $id]);
            
            if ($stmt->rowCount() > 0) {
                echo json_encode(["status" => "success", "message" => "User deleted successfully"]);
            } else {
                http_response_code(404);
                echo json_encode(["status" => "error", "message" => "User not found"]);
            }
            exit();
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Database Error: " . $e->getMessage()]);
            exit();
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
        exit();
        break;
}