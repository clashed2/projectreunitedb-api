<?php
require 'db.php';
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PATCH, DELETE");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        // View Single Report with linked categories
        $stmt = $pdo->prepare("
            SELECT reports.*, GROUP_CONCAT(categories.name SEPARATOR ', ') AS category_names
            FROM reports
            LEFT JOIN category_report ON reports.id = category_report.report_id
            LEFT JOIN categories ON category_report.category_id = categories.id
            WHERE reports.id = ?
            GROUP BY reports.id
        ");
        $stmt->execute([$_GET['id']]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($report) {
            echo json_encode(["status" => "success", "data" => $report]);
        } else {
            echo json_encode(["status" => "error", "message" => "Report not found"]);
        }
    } else {
        // View All Reports with linked categories
        $stmt = $pdo->prepare("
            SELECT reports.*, GROUP_CONCAT(categories.name SEPARATOR ', ') AS category_names
            FROM reports
            LEFT JOIN category_report ON reports.id = category_report.report_id
            LEFT JOIN categories ON category_report.category_id = categories.id
            GROUP BY reports.id
        ");
        $stmt->execute();
        echo json_encode(["status" => "success", "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
} 
elseif ($method === 'POST') {
    try {
        $pdo->beginTransaction();

        // 1. Create the Report (Matches your reports schema)
        $stmt = $pdo->prepare("INSERT INTO reports (user_id_who_filed, location) VALUES (?, ?)");
        $stmt->execute([
            $input['user_id_who_filed'], 
            $input['location']
        ]);
        $report_id = $pdo->lastInsertId();

        // 2. Link Categories in the junction table
        if (isset($input['category_ids']) && is_array($input['category_ids'])) {
            $stmtCat = $pdo->prepare("INSERT INTO category_report (report_id, category_id) VALUES (?, ?)");
            foreach ($input['category_ids'] as $cat_id) {
                $stmtCat->execute([$report_id, $cat_id]);
            }
        }

        // 3. Create the Linked Item (Matches your items table payload)
        $stmtItem = $pdo->prepare("INSERT INTO items (report_id, item_name, details_description) VALUES (?, ?, ?)");
        $stmtItem->execute([
            $report_id, 
            $input['item_name'], 
            $input['description']
        ]);

        $pdo->commit();
        echo json_encode(["status" => "success", "message" => "Report created", "report_id" => $report_id]);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(["status" => "error", "message" => "Failed: " . $e->getMessage()]);
    }
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
?>