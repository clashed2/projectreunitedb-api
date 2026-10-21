<?php
ob_start();
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        ob_clean();
        if (isset($_GET['id'])) {
            // View a single item by its ID
            $stmt = $pdo->prepare("SELECT * FROM items WHERE id = :id");
            $stmt->execute(['id' => $_GET['id']]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($item) {
                echo json_encode(["status" => "success", "data" => $item]);
            } else {
                http_response_code(404);
                echo json_encode(["status" => "error", "message" => "Item not found"]);
            }
        } else if (isset($_GET['report_id'])) {
            // View item linked to a specific report ID
            $stmt = $pdo->prepare("SELECT * FROM items WHERE report_id = :report_id");
            $stmt->execute(['report_id' => $_GET['report_id']]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($item) {
                echo json_encode(["status" => "success", "data" => $item]);
            } else {
                http_response_code(404);
                echo json_encode(["status" => "error", "message" => "Item not found for this report"]);
            }
        } else {
            // View all items
            $stmt = $pdo->query("SELECT * FROM items ORDER BY id DESC");
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["status" => "success", "data" => $items]);
        }
        exit();
        break;

    case 'POST':
        $content = file_get_contents("php://input");
        $data = json_decode($content, true);
        if (is_null($data)) { $data = $_POST; }

        if (empty($data['report_id']) || empty($data['details_description'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Missing required fields"]);
            exit();
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO items (report_id, picture_image, details_description) VALUES (:rid, :pic, :desc)");
            $stmt->execute([
                'rid'  => $data['report_id'],
                'pic'  => $data['picture_image'] ?? null,
                'desc' => $data['details_description']
            ]);
            ob_clean();
            echo json_encode(["status" => "success", "message" => "Item created successfully"]);
            exit();
        } catch (PDOException $e) {
            ob_clean();
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Database Error: " . $e->getMessage()]);
            exit();
        }
        break;

    case 'PATCH':
        ob_clean();
        $id = $_GET['id'] ?? null;
        $content = file_get_contents("php://input");
        $data = json_decode($content, true);
        if (!$id && isset($data['id'])) { $id = $data['id']; }

        if (!$id) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Missing item ID for update"]);
            exit();
        }

        try {
            $description = $data['details_description'] ?? null;
            if ($description) {
                $stmt = $pdo->prepare("UPDATE items SET details_description = :desc WHERE id = :id");
                $stmt->execute(['desc' => $description, 'id' => $id]);
                echo json_encode(["status" => "success", "message" => "Item updated successfully"]);
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
        ob_clean();
        $id = $_GET['id'] ?? null;
        if (!$id) {
            $content = file_get_contents("php://input");
            $data = json_decode($content, true);
            $id = $data['id'] ?? null;
        }

        if (!$id) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Missing item ID for deletion"]);
            exit();
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM items WHERE id = :id");
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() > 0) {
                echo json_encode(["status" => "success", "message" => "Item deleted successfully"]);
            } else {
                http_response_code(404);
                echo json_encode(["status" => "error", "message" => "Item not found"]);
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