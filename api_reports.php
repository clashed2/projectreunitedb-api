<?php
require 'db.php';

// Backward compatibility: serve legacy files from /uploads/ if they still exist
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
header("Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS");

/**
 * Safely ensures required columns exist so images and Lost/Found claim states persist across restarts.
 */
function ensureSchema(PDO $pdo) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM reports")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('report_type', $cols)) {
            $pdo->exec("ALTER TABLE reports ADD COLUMN report_type VARCHAR(20) NOT NULL DEFAULT 'LOST'");
        }
        if (!in_array('claim_status', $cols)) {
            $pdo->exec("ALTER TABLE reports ADD COLUMN claim_status VARCHAR(20) NOT NULL DEFAULT 'OPEN'");
        }
        if (!in_array('claimant_id', $cols)) {
            $pdo->exec("ALTER TABLE reports ADD COLUMN claimant_id INT NULL");
        }
        if (!in_array('claimant_name', $cols)) {
            $pdo->exec("ALTER TABLE reports ADD COLUMN claimant_name VARCHAR(120) NULL");
        }
        if (!in_array('claimant_contact', $cols)) {
            $pdo->exec("ALTER TABLE reports ADD COLUMN claimant_contact VARCHAR(120) NULL");
        }
        if (!in_array('claim_note', $cols)) {
            $pdo->exec("ALTER TABLE reports ADD COLUMN claim_note TEXT NULL");
        }
        if (!in_array('date_claimed', $cols)) {
            $pdo->exec("ALTER TABLE reports ADD COLUMN date_claimed DATETIME NULL");
        }
        $pdo->exec("ALTER TABLE reports MODIFY COLUMN photo_image LONGTEXT NULL");
        $pdo->exec("ALTER TABLE items MODIFY COLUMN picture_image LONGTEXT NULL");
    } catch (Exception $ignored) {
        // Ignore if already migrated or restricted
    }
}

ensureSchema($pdo);

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
if (is_null($input)) {
    $input = $_POST;
}

try {
    if ($method === 'GET') {
        $sql = "SELECT r.*,
                       u.name AS filed_by_user,
                       u.email AS filer_email,
                       u.phone_number AS filer_phone,
                       MAX(i.details_description) AS details_description,
                       MAX(i.picture_image) AS picture_image,
                       GROUP_CONCAT(DISTINCT c.name SEPARATOR ', ') AS category_name
                FROM reports r
                LEFT JOIN users u ON r.user_id_who_filed = u.id
                LEFT JOIN items i ON r.id = i.report_id
                LEFT JOIN category_report cr ON r.id = cr.report_id
                LEFT JOIN categories c ON cr.category_id = c.id";

        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare($sql . " WHERE r.id = ? GROUP BY r.id");
            $stmt->execute([$_GET['id']]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->prepare($sql . " GROUP BY r.id ORDER BY r.id DESC");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode(["status" => "success", "data" => $data]);
    }
    elseif ($method === 'POST') {
        $pdo->beginTransaction();

        $user_id_input = isset($input['user_id_who_filed']) ? (int)$input['user_id_who_filed'] : 1;

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

        $custom_report_id = 'REP-' . strtoupper(substr(md5(uniqid()), 0, 5));

        $report_type = strtoupper(trim($input['report_type'] ?? 'LOST'));
        if ($report_type !== 'FOUND') {
            $report_type = 'LOST';
        }

        // Store image as persistent Data URI in MySQL so it survives container restarts
        $raw_image = $input['photo_image'] ?? ($input['encoded_image'] ?? ($input['picture_image'] ?? null));
        $photo_image = null;
        if (!empty($raw_image)) {
            $clean_data = trim($raw_image);
            if (strpos($clean_data, 'http://') === 0 or strpos($clean_data, 'https://') === 0 or strpos($clean_data, 'data:image') === 0) {
                $photo_image = $clean_data;
            } else {
                if (strpos($clean_data, 'base64,') !== false) {
                    $clean_data = explode('base64,', $clean_data)[1];
                }
                $photo_image = 'data:image/jpeg;base64,' . $clean_data;
            }
        }

        $date_filed = date('Y-m-d H:i:s');
        if (!empty($input['date_filed'])) {
            $cleanDate = preg_replace('/^(Today|Yesterday)\s*\((.*?)\)/i', '$2', $input['date_filed']);
            $cleanDate = preg_replace('/Current Time\s*\((.*?)\)/i', '$1', $cleanDate);
            $parsed = strtotime($cleanDate);
            if ($parsed !== false) {
                $date_filed = date('Y-m-d H:i:s', $parsed);
            }
        }

        $location_val = isset($input['location']) ? substr(trim($input['location']), 0, 200) : 'Unknown Location';

        $stmt = $pdo->prepare("INSERT INTO reports (report_id, user_id_who_filed, location, photo_image, date_filed, report_type, claim_status) VALUES (?, ?, ?, ?, ?, ?, 'OPEN')");
        $stmt->execute([
            $custom_report_id,
            $user_id,
            $location_val,
            $photo_image,
            $date_filed,
            $report_type
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

        $item_name = trim($input['item_name'] ?? 'Unknown Item');
        $description = trim($input['description'] ?? 'No description provided');
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
        if (!isset($_GET['id'])) {
            echo json_encode(["status" => "error", "message" => "Missing ID data"]);
            exit();
        }
        $id = (int)$_GET['id'];
        $action = $input['action'] ?? '';

        if ($action === 'request_claim') {
            $stmt = $pdo->prepare("UPDATE reports SET claim_status = 'PENDING', claimant_id = ?, claimant_name = ?, claimant_contact = ?, claim_note = ? WHERE id = ?");
            $stmt->execute([
                (int)($input['claimant_id'] ?? 0),
                trim($input['claimant_name'] ?? 'Community Member'),
                trim($input['claimant_contact'] ?? ''),
                trim($input['claim_note'] ?? ''),
                $id
            ]);
        } elseif (in_array($action, ['cancel_claim', 'decline_claim'], true)) {
            $stmt = $pdo->prepare("UPDATE reports SET claim_status = 'OPEN', claimant_id = NULL, claimant_name = NULL, claimant_contact = NULL, claim_note = NULL WHERE id = ?");
            $stmt->execute([$id]);
        } elseif ($action === 'accept_claim' or !empty($input['status']) or !empty($input['report_type'])) {
            $stmt = $pdo->prepare("UPDATE reports SET claim_status = 'ACCEPTED', date_claimed = NOW() WHERE id = ?");
            $stmt->execute([$id]);
        }

        if (isset($input['location'])) {
            $stmt = $pdo->prepare("UPDATE reports SET location = ? WHERE id = ?");
            $stmt->execute([substr($input['location'], 0, 200), $id]);
        }

        echo json_encode(["status" => "success", "message" => "Report updated"]);
    }
    elseif ($method === 'DELETE') {
        if (isset($_GET['id'])) {
            $pdo->prepare("DELETE FROM category_report WHERE report_id = ?")->execute([$_GET['id']]);
            $pdo->prepare("DELETE FROM items WHERE report_id = ?")->execute([$_GET['id']]);
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