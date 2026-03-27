<?php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

try {
    // Get database connection
    $database = getMongoDBConnection();
    $collection = $database->principal_investigators;
    
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'GET':
            handleGet($collection);
            break;
            
        case 'POST':
            handlePost($collection);
            break;
            
        case 'PUT':
            handlePut($collection);
            break;
            
        case 'DELETE':
            handleDelete($collection);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => $e->getMessage()
    ]);
}

// GET - Fetch all PIs or single PI by ID
function handleGet($collection) {
    try {
        // Check if ID is provided
        if (isset($_GET['id'])) {
            $id = $_GET['id'];
            
            // Validate ObjectId format
            if (!preg_match('/^[a-f\d]{24}$/i', $id)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid ID format'
                ]);
                return;
            }
            
            // Fetch single PI
            $pi = $collection->findOne(['_id' => new ObjectId($id)]);
            
            if ($pi) {
                $pi['id'] = (string)$pi['_id'];
                unset($pi['_id']);
                
                // Convert dates to ISO string
                if (isset($pi['created_at'])) {
                    $pi['created_at'] = $pi['created_at']->toDateTime()->format('c');
                }
                if (isset($pi['updated_at'])) {
                    $pi['updated_at'] = $pi['updated_at']->toDateTime()->format('c');
                }
                
                echo json_encode([
                    'success' => true,
                    'data' => $pi
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'PI not found'
                ]);
            }
        } else {
            // Fetch all PIs with optional search and filters
            $filter = [];
            
            // Handle search parameter
            if (isset($_GET['search']) && !empty($_GET['search'])) {
                $searchTerm = trim($_GET['search']);
                $filter['$or'] = [
                    ['name' => new MongoDB\BSON\Regex($searchTerm, 'i')],
                    ['email' => new MongoDB\BSON\Regex($searchTerm, 'i')],
                    ['department' => new MongoDB\BSON\Regex($searchTerm, 'i')]
                ];
            }
            
            // Handle department filter
            if (isset($_GET['department']) && !empty($_GET['department'])) {
                $filter['department'] = trim($_GET['department']);
            }
            
            // Handle designation filter
            if (isset($_GET['designation']) && !empty($_GET['designation'])) {
                $filter['designation'] = trim($_GET['designation']);
            }
            
            // Fetch PIs
            $pis = $collection->find($filter, [
                'sort' => ['created_at' => -1]
            ]);
            
            $result = [];
            foreach ($pis as $pi) {
                $pi['id'] = (string)$pi['_id'];
                unset($pi['_id']);
                
                // Convert dates to ISO string
                if (isset($pi['created_at'])) {
                    $pi['created_at'] = $pi['created_at']->toDateTime()->format('c');
                }
                if (isset($pi['updated_at'])) {
                    $pi['updated_at'] = $pi['updated_at']->toDateTime()->format('c');
                }
                
                $result[] = $pi;
            }
            
            echo json_encode([
                'success' => true,
                'data' => $result,
                'count' => count($result)
            ]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error fetching PIs: ' . $e->getMessage()
        ]);
    }
}

// POST - Create new PI
function handlePost($collection) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid JSON data'
            ]);
            return;
        }
        
        // Validate required fields
        $required = ['name', 'email', 'phone', 'department', 'designation'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => "Field '{$field}' is required"
                ]);
                return;
            }
        }
        
        // Validate email format
        if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid email format'
            ]);
            return;
        }
        
        // Check if email already exists
        $existingPI = $collection->findOne(['email' => strtolower(trim($input['email']))]);
        if ($existingPI) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'PI with this email already exists'
            ]);
            return;
        }
        
        // Validate designation
        $validDesignations = ['Professor', 'Associate Professor', 'Assistant Professor', 'Senior Scientist', 'Scientist'];
        if (!in_array($input['designation'], $validDesignations)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid designation. Must be one of: ' . implode(', ', $validDesignations)
            ]);
            return;
        }
        
        // Prepare document
        $document = [
            'name' => trim($input['name']),
            'email' => strtolower(trim($input['email'])),
            'phone' => trim($input['phone']),
            'department' => trim($input['department']),
            'designation' => $input['designation'],
            'created_at' => new UTCDateTime(),
            'updated_at' => new UTCDateTime()
        ];
        
        // Insert document
        $result = $collection->insertOne($document);
        
        if ($result->getInsertedCount() > 0) {
            $document['id'] = (string)$result->getInsertedId();
            $document['created_at'] = $document['created_at']->toDateTime()->format('c');
            $document['updated_at'] = $document['updated_at']->toDateTime()->format('c');
            unset($document['_id']);
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'PI registered successfully',
                'data' => $document
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to register PI'
            ]);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error creating PI: ' . $e->getMessage()
        ]);
    }
}

// PUT - Update existing PI
function handlePut($collection) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid JSON data'
            ]);
            return;
        }
        
        // Get ID from query string or body
        $id = $_GET['id'] ?? $input['id'] ?? null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'PI ID is required'
            ]);
            return;
        }
        
        // Validate ObjectId format
        if (!preg_match('/^[a-f\d]{24}$/i', $id)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid ID format'
            ]);
            return;
        }
        
        // Remove id from update data
        unset($input['id']);
        
        // Check if PI exists
        $existingPI = $collection->findOne(['_id' => new ObjectId($id)]);
        if (!$existingPI) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'PI not found'
            ]);
            return;
        }
        
        // Validate email if provided and changed
        if (!empty($input['email']) && $input['email'] !== $existingPI['email']) {
            if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid email format'
                ]);
                return;
            }
            
            // Check if new email already exists
            $duplicateEmail = $collection->findOne([
                'email' => strtolower(trim($input['email'])),
                '_id' => ['$ne' => new ObjectId($id)]
            ]);
            
            if ($duplicateEmail) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'message' => 'Email already in use'
                ]);
                return;
            }
        }
        
        // Validate designation if provided
        if (!empty($input['designation'])) {
            $validDesignations = ['Professor', 'Associate Professor', 'Assistant Professor', 'Senior Scientist', 'Scientist'];
            if (!in_array($input['designation'], $validDesignations)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid designation'
                ]);
                return;
            }
        }
        
        // Prepare update document
        $updateData = [];
        $allowedFields = ['name', 'email', 'phone', 'department', 'designation'];
        
        foreach ($allowedFields as $field) {
            if (isset($input[$field]) && !empty($input[$field])) {
                $updateData[$field] = trim($input[$field]);
                if ($field === 'email') {
                    $updateData[$field] = strtolower($updateData[$field]);
                }
            }
        }
        
        if (empty($updateData)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'No valid fields to update'
            ]);
            return;
        }
        
        $updateData['updated_at'] = new UTCDateTime();
        
        // Update document
        $result = $collection->updateOne(
            ['_id' => new ObjectId($id)],
            ['$set' => $updateData]
        );
        
        if ($result->getModifiedCount() > 0 || $result->getMatchedCount() > 0) {
            // Fetch updated document
            $updatedPI = $collection->findOne(['_id' => new ObjectId($id)]);
            $updatedPI['id'] = (string)$updatedPI['_id'];
            unset($updatedPI['_id']);
            
            // Convert dates
            if (isset($updatedPI['created_at'])) {
                $updatedPI['created_at'] = $updatedPI['created_at']->toDateTime()->format('c');
            }
            if (isset($updatedPI['updated_at'])) {
                $updatedPI['updated_at'] = $updatedPI['updated_at']->toDateTime()->format('c');
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'PI updated successfully',
                'data' => $updatedPI
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to update PI'
            ]);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error updating PI: ' . $e->getMessage()
        ]);
    }
}

// DELETE - Delete PI
function handleDelete($collection) {
    try {
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'PI ID is required'
            ]);
            return;
        }
        
        // Validate ObjectId format
        if (!preg_match('/^[a-f\d]{24}$/i', $id)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid ID format'
            ]);
            return;
        }
        
        // Check if PI exists
        $pi = $collection->findOne(['_id' => new ObjectId($id)]);
        if (!$pi) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'PI not found'
            ]);
            return;
        }
        
        // Delete document
        $result = $collection->deleteOne(['_id' => new ObjectId($id)]);
        
        if ($result->getDeletedCount() > 0) {
            echo json_encode([
                'success' => true,
                'message' => 'PI deleted successfully'
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to delete PI'
            ]);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error deleting PI: ' . $e->getMessage()
        ]);
    }
}
?>
