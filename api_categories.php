<?php
require 'db.php';
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PATCH, DELETE");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

try {
    if ($method === 'GET') {
        if (isset($_GET['id'])) {
            // View a single category by ID
            $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            $category = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($category) {
                echo json_encode(["status" => "success", "data" => $category]);
            } else {
                echo json_encode(["status" => "error", "message" => "Category not found"]);
            }
        } else {
            // View all categories
            $stmt = $pdo->query("SELECT * FROM categories ORDER BY name ASC");
            echo json_encode(["status" => "success", "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }
    } 
    elseif ($method === 'POST') {
        // Create a new category
        if (!empty($input['name'])) {
            $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
            $stmt->execute([$input['name']]);
            echo json_encode(["status" => "success", "message" => "Category added", "id" => $pdo->lastInsertId()]);
        } else {
            echo json_encode(["status" => "error", "message" => "Category name is required"]);
        }
    } 
    elseif ($method === 'PATCH') {
        // Update an existing category name
        if (isset($_GET['id']) && !empty($input['name'])) {
            $stmt = $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?");
            $stmt->execute([$input['name'], $_GET['id']]);
            echo json_encode(["status" => "success", "message" => "Category updated successfully"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Category ID and new name are required"]);
        }
    } 
    elseif ($method === 'DELETE') {
        // Delete a category
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            echo json_encode(["status" => "success", "message" => "Category deleted successfully"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Missing category ID for deletion"]);
        }
    }
} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
}
?>