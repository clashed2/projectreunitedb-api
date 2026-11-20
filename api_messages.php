<?php
require 'db.php';

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

function ensureMessagesTable(PDO $pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT NOT NULL,
            sender_id INT NOT NULL,
            receiver_id INT NOT NULL,
            message TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_report_users (report_id, sender_id, receiver_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $ignored) {}
}

ensureMessagesTable($pdo);

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
if (is_null($input)) {
    $input = $_POST;
}

try {
    if ($method === 'GET') {
        // Mode 1: Fetch full message thread between two users for a specific report
        if (isset($_GET['report_id']) and isset($_GET['user_id']) and isset($_GET['other_id'])) {
            $reportId = (int)$_GET['report_id'];
            $userId = (int)$_GET['user_id'];
            $otherId = (int)$_GET['other_id'];

            $stmt = $pdo->prepare("
                SELECT m.*, u.name AS sender_name
                FROM messages m
                LEFT JOIN users u ON m.sender_id = u.id
                WHERE m.report_id = ?
                  AND ((m.sender_id = ? AND m.receiver_id = ?)
                    OR (m.sender_id = ? AND m.receiver_id = ?))
                ORDER BY m.id ASC
            ");
            $stmt->execute([$reportId, $userId, $otherId, $otherId, $userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(["status" => "success", "data" => $rows]);
            exit();
        }

        // Mode 2: Fetch inbox conversation list for a user
        if (isset($_GET['user_id'])) {
            $userId = (int)$_GET['user_id'];

            $stmt = $pdo->prepare("
                SELECT
                    m.id,
                    m.report_id,
                    CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END AS other_user_id,
                    u.name AS other_user_name,
                    m.message AS last_message,
                    m.created_at AS last_time,
                    r.report_type,
                    r.claim_status,
                    i.details_description
                FROM messages m
                INNER JOIN (
                    SELECT MAX(id) AS max_id
                    FROM messages
                    WHERE sender_id = ? OR receiver_id = ?
                    GROUP BY report_id, LEAST(sender_id, receiver_id), GREATEST(sender_id, receiver_id)
                ) latest ON m.id = latest.max_id
                LEFT JOIN users u ON u.id = (CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END)
                LEFT JOIN reports r ON r.id = m.report_id
                LEFT JOIN items i ON i.report_id = m.report_id
                ORDER BY m.id DESC
            ");
            $stmt->execute([$userId, $userId, $userId, $userId]);
            $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(["status" => "success", "data" => $conversations]);
            exit();
        }

        echo json_encode(["status" => "error", "message" => "Missing parameters"]);
    }
    elseif ($method === 'POST') {
        $reportId = (int)($input['report_id'] ?? 0);
        $senderId = (int)($input['sender_id'] ?? 0);
        $receiverId = (int)($input['receiver_id'] ?? 0);
        $message = trim($input['message'] ?? '');

        if ($reportId <= 0 or $senderId <= 0 or $receiverId <= 0 or $message === '') {
            echo json_encode(["status" => "error", "message" => "Missing message details"]);
            exit();
        }

        $stmt = $pdo->prepare("INSERT INTO messages (report_id, sender_id, receiver_id, message) VALUES (?, ?, ?, ?)");
        $stmt->execute([$reportId, $senderId, $receiverId, $message]);

        echo json_encode([
            "status" => "success",
            "message" => "Message sent",
            "id" => $pdo->lastInsertId()
        ]);
    }
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "SQL Error: " . $e->getMessage()]);
}
?>