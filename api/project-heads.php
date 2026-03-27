<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

$db = getMongoDBConnection();
$collection = $db->project_heads;

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            $id = $_GET['id'] ?? null;
            
            if ($id) {
                // Get single project head
                $head = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id)]);
                
                if (!$head) {
                    http_response_code(404);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Project head not found'
                    ]);
                    exit;
                }
                
                $head['id'] = (string) $head['_id'];
                unset($head['_id']);
                
                echo json_encode([
                    'success' => true,
                    'data' => $head
                ]);
            } else {
                // Get all active project heads
                $filter = ['isActive' => true];
                
                // Optional filters
                if (isset($_GET['type']) && !empty($_GET['type'])) {
                    $filter['type'] = $_GET['type'];
                }
                
                $cursor = $collection->find($filter, [
                    'sort' => ['name' => 1]
                ]);
                
                $heads = [];
                foreach ($cursor as $head) {
                    $head['id'] = (string) $head['_id'];
                    unset($head['_id']);
                    
                    // Format dates
                    if (isset($head['createdAt'])) {
                        $head['createdAt'] = $head['createdAt']->toDateTime()->format('Y-m-d H:i:s');
                    }
                    if (isset($head['updatedAt'])) {
                        $head['updatedAt'] = $head['updatedAt']->toDateTime()->format('Y-m-d H:i:s');
                    }
                    
                    $heads[] = $head;
                }
                
                echo json_encode([
                    'success' => true,
                    'data' => $heads,
                    'count' => count($heads)
                ]);
            }
            break;

        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON: ' . json_last_error_msg());
            }
            
            // Validate required fields
            if (empty($data['name']) || empty($data['type'])) {
                throw new Exception('Name and type are required');
            }
            
            // Check if head with same name already exists
            $existing = $collection->findOne(['name' => $data['name']]);
            if ($existing) {
                throw new Exception('Project head with this name already exists');
            }
            
            $document = [
                'name' => htmlspecialchars(strip_tags($data['name'])),
                'type' => htmlspecialchars(strip_tags($data['type'])),
                'description' => htmlspecialchars(strip_tags($data['description'] ?? '')),
                'isActive' => true,
                'createdAt' => new MongoDB\BSON\UTCDateTime(),
                'updatedAt' => new MongoDB\BSON\UTCDateTime()
            ];
            
            $result = $collection->insertOne($document);
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Project head created successfully',
                'id' => (string) $result->getInsertedId()
            ]);
            break;

        case 'PUT':
            $id = $_GET['id'] ?? null;
            
            if (!$id) {
                throw new Exception('ID is required');
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON: ' . json_last_error_msg());
            }
            
            $updateData = ['$set' => ['updatedAt' => new MongoDB\BSON\UTCDateTime()]];
            
            if (isset($data['name'])) {
                $updateData['$set']['name'] = htmlspecialchars(strip_tags($data['name']));
            }
            if (isset($data['type'])) {
                $updateData['$set']['type'] = htmlspecialchars(strip_tags($data['type']));
            }
            if (isset($data['description'])) {
                $updateData['$set']['description'] = htmlspecialchars(strip_tags($data['description']));
            }
            if (isset($data['isActive'])) {
                $updateData['$set']['isActive'] = (bool) $data['isActive'];
            }
            
            $result = $collection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($id)],
                $updateData
            );
            
            if ($result->getModifiedCount() > 0 || $result->getMatchedCount() > 0) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Project head updated successfully'
                ]);
            } else {
                throw new Exception('Failed to update project head');
            }
            break;

        case 'DELETE':
            $id = $_GET['id'] ?? null;
            
            if (!$id) {
                throw new Exception('ID is required');
            }
            
            // Soft delete - mark as inactive
            $result = $collection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($id)],
                ['$set' => [
                    'isActive' => false,
                    'updatedAt' => new MongoDB\BSON\UTCDateTime()
                ]]
            );
            
            if ($result->getModifiedCount() > 0) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Project head deleted successfully'
                ]);
            } else {
                throw new Exception('Failed to delete project head');
            }
            break;

        default:
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'message' => 'Method not allowed'
            ]);
            break;
    }

} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>