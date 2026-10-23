<?php
require 'db.php';
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PATCH, DELETE");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

try {
    if ($method === 'GET') {
        $sql = "SELECT r.*, i.details_description, i.picture_image, 
                       GROUP_CONCAT(c.name SEPARATOR ', ') AS category_names
                FROM reports r
                LEFT JOIN items i ON r.id = i.report_id
                LEFT JOIN category_report cr ON r.id = cr.report_id
                LEFT JOIN categories c ON cr.category_id = c.id";

        if (isset($_GET['id'])) {
            // Added i.details_description and i.picture_image to GROUP BY
            $stmt = $pdo->prepare($sql . " WHERE r.id = ? GROUP BY r.id, i.details_description, i.picture_image");
            $stmt->execute([$_GET['id']]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            // Added i.details_description and i.picture_image to GROUP BY
            $stmt = $pdo->prepare($sql . " GROUP BY r.id, i.details_description, i.picture_image ORDER BY r.created_at DESC");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode(["status" => "success", "data" => $data]);
    }
    elseif ($method === 'POST') {
        $pdo->beginTransaction();

        $custom_report_id = 'REP-' . strtoupper(substr(md5(uniqid()), 0, 5));

        $stmt = $pdo->prepare("INSERT INTO reports (report_id, user_id_who_filed, location, date_filed) VALUES (?, ?, ?, NOW())");
        $stmt->execute([
            $custom_report_id,
            $input['user_id_who_filed'], 
            $input['location']
        ]);
        $primary_db_id = $pdo->lastInsertId();

        if (!empty($input['category_ids']) && is_array($input['category_ids'])) {
            $stmtCat = $pdo->prepare("INSERT INTO category_report (category_id, report_id) VALUES (?, ?)");
            foreach ($input['category_ids'] as $cat_id) {
                $stmtCat->execute([$cat_id, $primary_db_id]);
            }
        }

        $item_name = isset($input['item_name']) ? $input['item_name'] : 'Unknown Item';
        $description = isset($input['description']) ? $input['description'] : 'No description';
        $full_details = $item_name . " - " . $description;

        $stmtItem = $pdo->prepare("INSERT INTO items (report_id, details_description) VALUES (?, ?)");
        $stmtItem->execute([
            $primary_db_id, 
            $full_details
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
            $stmt->execute([$input['location'], $_GET['id']]);
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
    echo json_encode(["status" => "error", "message" => "System Error: " . $e->getMessage()]);
}
?>