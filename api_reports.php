<?php
require 'db.php';

// Serve uploaded image directly via PHP endpoint
if (isset($_GET['image'])) {
    $img_name = basename($_GET['image']);
    $file_path = __DIR__ . '/uploads/' . $img_name;
    if (file_exists($file_path)) {
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($file_path));
        header("Access-Control-Allow-Origin: *");
        readfile($file_path);
        exit();
    } else {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Image not found"]);
        exit();
    }
}

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PATCH, DELETE");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
if (is_null($input)) { $input = $_POST; }

try {
    if ($method === 'GET') {
        $sql = "SELECT r.*, i.details_description, i.picture_image,
                       GROUP_CONCAT(c.name SEPARATOR ', ') AS category_names
                FROM reports r
                LEFT JOIN items i ON r.id = i.report_id
                LEFT JOIN category_report cr ON r.id = cr.report_id
                LEFT JOIN categories c ON cr.category_id = c.id";

        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare($sql . " WHERE r.id = ? GROUP BY r.id, i.details_description, i.picture_image");
            $stmt->execute([$_GET['id']]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->prepare($sql . " GROUP BY r.id, i.details_description, i.picture_image ORDER BY r.created_at DESC");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode(["status" => "success", "data" => $data]);
    }
    elseif ($method === 'POST') {
        $pdo->beginTransaction();

        $user_id_input = isset($input['user_id_who_filed']) ? $input['user_id_who_filed'] : 1;

        // Verify user exists in DB, otherwise fallback to first user or create guest
        $userCheck = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $userCheck->execute([$user_id_input]);
        if (!$userCheck->fetch()) {
            $fallbackUser = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetch();
            if ($fallbackUser) {
                $user_id = $fallbackUser['id'];
            } else {
                $pdo->exec("INSERT INTO users (name, username, email, password) VALUES ('Guest', 'guest@reunite.com', 'guest@reunite.com', 'password')");
                $user_id = $pdo->lastInsertId();
            }
        } else {
            $user_id = $user_id_input;
        }

        // Short 9-character unique report code (e.g. REP-A1B2C)
        $custom_report_id = 'REP-' . strtoupper(substr(md5(uniqid()), 0, 5));

        // Extract photo from payload
        $raw_image = null;
        if (!empty($input['photo_image'])) {
            $raw_image = $input['photo_image'];
        } elseif (!empty($input['encoded_image'])) {
            $raw_image = $input['encoded_image'];
        } elseif (!empty($input['picture_image'])) {
            $raw_image = $input['picture_image'];
        }

        $photo_image = null;
        if ($raw_image) {
            $clean_data = trim($raw_image);
            if (strpos($clean_data, 'http://') === 0 || strpos($clean_data, 'https://') === 0) {
                $photo_image = substr($clean_data, 0, 100);
            } elseif (strlen($clean_data) <= 100 && strpos($clean_data, '.') !== false) {
                $photo_image = $clean_data;
            } else {
                // Base64 Image: Decode and save to uploads/ directory as JPEG file
                if (strpos($clean_data, 'base64,') !== false) {
                    $clean_data = explode('base64,', $clean_data)[1];
                }
                $img_bytes = base64_decode($clean_data);
                if ($img_bytes !== false && strlen($img_bytes) > 0) {
                    $filename = 'img_' . time() . '_' . substr(md5(uniqid()), 0, 5) . '.jpg';
                    $upload_dir = __DIR__ . '/uploads/';
                    if (!file_exists($upload_dir)) {
                        @mkdir($upload_dir, 0777, true);
                    }
                    if (@file_put_contents($upload_dir . $filename, $img_bytes) !== false) {
                        $photo_image = $filename; // Short 22-character filename
                    }
                }
            }
        }

        // Format date_filed safely for MySQL DATETIME (YYYY-MM-DD HH:MM:SS)
        $date_filed = date('Y-m-d H:i:s');
        if (!empty($input['date_filed'])) {
            $parsed = strtotime($input['date_filed']);
            if ($parsed !== false) {
                $date_filed = date('Y-m-d H:i:s', $parsed);
            }
        }

        // Clean location (max 200 chars)
        $location_val = isset($input['location']) ? substr($input['location'], 0, 200) : 'Unknown Location';

        $stmt = $pdo->prepare("INSERT INTO reports (report_id, user_id_who_filed, location, photo_image, date_filed) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $custom_report_id,
            $user_id,
            $location_val,
            $photo_image,
            $date_filed
        ]);
        $primary_db_id = $pdo->lastInsertId();

        if (!empty($input['category_ids']) && is_array($input['category_ids'])) {
            $stmtCat = $pdo->prepare("INSERT INTO category_report (category_id, report_id) VALUES (?, ?)");
            foreach ($input['category_ids'] as $cat_id) {
                $catCheck = $pdo->prepare("SELECT id FROM categories WHERE id = ?");
                $catCheck->execute([$cat_id]);
                if ($catCheck->fetch()) {
                    $stmtCat->execute([$cat_id, $primary_db_id]);
                }
            }
        }

        $item_name = isset($input['item_name']) ? $input['item_name'] : 'Unknown Item';
        $description = isset($input['description']) ? $input['description'] : 'No description';
        $full_details = $item_name . " - " . $description;

        $stmtItem = $pdo->prepare("INSERT INTO items (report_id, details_description, picture_image) VALUES (?, ?, ?)");
        $stmtItem->execute([
            $primary_db_id,
            $full_details,
            $photo_image
        ]);

        $pdo->commit();
        echo json_encode([
            "status" => "success",
            "message" => "Report created",
            "internal_id" => $primary_db_id,
            "report_code" => $custom_report_id
        ]);
    }
    elseif ($method === 'PATCH') {
        if (isset($_GET['id']) && isset($input['location'])) {
            $stmt = $pdo->prepare("UPDATE reports SET location = ? WHERE id = ?");
            $stmt->execute([substr($input['location'], 0, 200), $_GET['id']]);
            echo json_encode(["status" => "success", "message" => "Report location updated"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Missing ID or location data"]);
        }
    }
    elseif ($method === 'DELETE') {
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("DELETE FROM reports WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            echo json_encode(["status" => "success", "message" => "Report deleted"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Missing report ID for deletion"]);
        }
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["status" => "error", "message" => "SQL Error: " . $e->getMessage()]);
}
?>