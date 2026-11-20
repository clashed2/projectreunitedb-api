<?php
require 'db.php';

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS");

function ensureUserProfileColumns(PDO $pdo) {
    try {
        $cols =$pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('phone_number', $cols)) {$pdo->exec("ALTER TABLE users ADD COLUMN phone_number VARCHAR(50) NULL");
        }
        if (!in_array('avatar_image', $cols)) {$pdo->exec("ALTER TABLE users ADD COLUMN avatar_image LONGTEXT NULL");
        }
    } catch (Exception $ignored) {}
}

ensureUserProfileColumns($pdo);

$method = $_SERVER['REQUEST_METHOD'];$input = json_decode(file_get_contents('php://input'), true);
if (is_null($input)) {
    $input =$_POST;
}

try {
    if ($method === 'GET') {$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
        $email = isset($_GET['email']) ? trim($_GET['email']) : '';

        if ($userId > 0) {
            $stmt =$pdo->prepare("SELECT id, name, username, email, phone_number, avatar_image FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
        } elseif ($email !== '') {
            $stmt =$pdo->prepare("SELECT id, name, username, email, phone_number, avatar_image FROM users WHERE email = ? OR username = ? LIMIT 1");
            $stmt->execute([$email,$email]);
        } else {
            echo json_encode(["status" => "error", "message" => "Missing user identifier"]);
            exit();
        }

        $user =$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            echo json_encode(["status" => "error", "message" => "User not found"]);
            exit();
        }

        $uid = (int)$user['id'];

        $countStmt =$pdo->prepare("
            SELECT
                COUNT(*) AS total_reported,
                SUM(CASE WHEN UPPER(claim_status) = 'ACCEPTED' THEN 1 ELSE 0 END) AS total_reunited
            FROM reports
            WHERE user_id_who_filed = ?
        ");
        $countStmt->execute([$uid]);
        $stats =$countStmt->fetch(PDO::FETCH_ASSOC);

        $user['total_reported'] = (int)($stats['total_reported'] ?? 0);
        $user['total_reunited'] = (int)($stats['total_reunited'] ?? 0);

        echo json_encode(["status" => "success", "data" => $user]);
    }
    elseif ($method === 'POST' or $method === 'PATCH') {$userId = (int)($input['user_id'] ?? ($_GET['user_id'] ?? 0));
        if ($userId <= 0) {
            echo json_encode(["status" => "error", "message" => "Invalid user ID"]);
            exit();
        }

        $fields = [];$params = [];

        if (isset($input['name']) and trim($input['name']) !== '') {$fields[] = "name = ?";
            $params[] = trim($input['name']);
        }

        if (array_key_exists('phone_number', $input)) {$fields[] = "phone_number = ?";
            $params[] = trim((string)$input['phone_number']);
        }

        if (!empty($input['avatar_image'])) {
            $rawImg = trim($input['avatar_image']);
            if (strpos($rawImg, 'data:image') === 0 or strpos($rawImg, 'http') === 0) {
                $cleanAvatar =$rawImg;
            } else {
                if (strpos($rawImg, 'base64,') !== false) {
                    $rawImg = explode('base64,',$rawImg)[1];
                }
                $cleanAvatar = 'data:image/jpeg;base64,' .$rawImg;
            }
            $fields[] = "avatar_image = ?";
            $params[] =$cleanAvatar;
        }

        if (empty($fields)) {
            echo json_encode(["status" => "error", "message" => "No fields to update"]);
            exit();
        }

        $params[] =$userId;
        $sql = "UPDATE users SET " . implode(", ", $fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $fetch =$pdo->prepare("SELECT id, name, username, email, phone_number, avatar_image FROM users WHERE id = ? LIMIT 1");
        $fetch->execute([$userId]);
        $updatedUser =$fetch->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => "success",
            "message" => "Profile updated",
            "data" => $updatedUser
        ]);
    }
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "SQL Error: " . $e->getMessage()]);
}
?>