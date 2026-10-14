<?php
ob_start();
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Joining reports, items, and users exactly as per your schema
        $query = "SELECT r.*, i.details_description, u.name as filed_by_user 
                  FROM reports r 
                  LEFT JOIN items i ON r.id = i.report_id 
                  LEFT JOIN users u ON r.user_id_who_filed = u.id 
                  ORDER BY r.id DESC";
        $stmt = $pdo->query($query);
        $reports = $stmt->fetchAll();
        ob_clean();
        echo json_encode(["status" => "success", "data" => $reports]);
        break;

    case 'POST':
        $content = file_get_contents("php://input");
        $data = json_decode($content, true);
        if (is_null($data)) { $data = $_POST; }

        if (empty($data['user_id_who_filed']) || empty($data['location'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Missing required fields"]);
            exit();
        }

        try {
            $image_name = null;
            if (!empty($data['encoded_image'])) {
                $encoded_image = preg_replace('/^data:image\/\w+;base64,/', '', $data['encoded_image']);
                $image_data = base64_decode($encoded_image);
                $image_name = 'img_' . time() . '_' . uniqid() . '.jpg';
                if (!file_exists('uploads')) { mkdir('uploads', 0777, true); }
                file_put_contents('uploads/' . $image_name, $image_data);
            }

            // 1. Insert into 'reports' table (Matches your schema columns)
            $sql = "INSERT INTO reports (report_id, user_id_who_filed, date_filed, photo_image, location) 
                    VALUES (:report_id, :user_id, NOW(), :photo, :loc)";
            $stmt = $pdo->prepare($sql);
            
            // Generate a unique string for the report_id VARCHAR column
            $readable_id = ($data['report_type'] ?? 'ITEM') . '-' . strtoupper(substr(uniqid(), -5));
            
            $stmt->execute([
                'report_id' => $readable_id,
                'user_id'   => $data['user_id_who_filed'],
                'photo'     => $image_name,
                'loc'       => $data['location']
            ]);
            
            // Get the INT id of the report we just created
            $new_report_internal_id = $pdo->lastInsertId();

            // 2. Insert into 'items' table (Matches your schema columns)
            // We use the internal INT id as the foreign key 'report_id'
            $full_description = ($data['item_name'] ?? 'Unnamed') . " - " . ($data['description'] ?? '');
            
            $stmtItem = $pdo->prepare("INSERT INTO items (report_id, picture_image, details_description) 
                                     VALUES (:rid, :pic, :desc)");
            $stmtItem->execute([
                'rid'  => $new_report_internal_id,
                'pic'  => $image_name, // Saving photo in items table too as per schema
                'desc' => $full_description
            ]);

            ob_clean();
            echo json_encode(["status" => "success", "message" => "Report filed successfully"]);
            exit();
        } catch (PDOException $e) {
            ob_clean();
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Database Error: " . $e->getMessage()]);
            exit();
        }
        break;
}