<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

ob_start();

try {
    require_once __DIR__ . '/../config/database.php';
    
    $db = getMongoDBConnection();
    
    if (!$db) {
        throw new Exception('Failed to connect to MongoDB');
    }
    
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method !== 'GET') {
        throw new Exception('Only GET method is allowed');
    }
    
    $projectId = $_GET['projectId'] ?? null;
    
    if (!$projectId) {
        throw new Exception('Project ID is required');
    }
    
    // Simply fetch from head_allocations collection
    $allocations = $db->head_allocations->find(
        ['projectId' => $projectId],
        ['sort' => ['headName' => 1]]
    );
    
    $allocationsArray = [];
    $totalSanctioned = 0;
    $totalReleased = 0;
    
    foreach ($allocations as $alloc) {
        $sanctioned = floatval($alloc['sanctionedAmount'] ?? 0);
        $released = floatval($alloc['releasedAmount'] ?? 0);
        
        $allocationsArray[] = [
            'id' => $alloc['headId'] ?? '',
            'headId' => $alloc['headId'] ?? null,
            'headName' => $alloc['headName'] ?? '',
            'headType' => $alloc['headType'] ?? 'recurring',
            'sanctionedAmount' => $sanctioned,
            'releasedAmount' => $released,
            'remainingAmount' => $sanctioned - $released,
            'status' => $alloc['status'] ?? 'sanctioned',
            'releaseHistory' => $alloc['releaseHistory'] ?? []
        ];
        
        $totalSanctioned += $sanctioned;
        $totalReleased += $released;
    }
    
    // If no allocations found, try to initialize from project
    if (empty($allocationsArray)) {
        $project = $db->projects->findOne([
            '_id' => new MongoDB\BSON\ObjectId($projectId)
        ]);
        
        if ($project && isset($project['heads'])) {
            $heads = $project['heads'];
            if ($heads instanceof MongoDB\Model\BSONArray) {
                $heads = iterator_to_array($heads);
            }
            
            if (is_array($heads) && count($heads) > 0) {
                // Initialize head_allocations for this project
                foreach ($heads as $head) {
                    if (is_object($head)) {
                        $head = (array) $head;
                    }
                    
                    $headId = $head['id'] ?? $head['headId'] ?? (string) new MongoDB\BSON\ObjectId();
                    $sanctioned = floatval($head['sanctionedAmount'] ?? 0);
                    
                    $allocationDoc = [
                        'projectId' => $projectId,
                        'gpNumber' => $project['gpNumber'] ?? '',
                        'headId' => $headId,
                        'headName' => $head['headName'] ?? '',
                        'headType' => $head['headType'] ?? 'recurring',
                        'sanctionedAmount' => $sanctioned,
                        'releasedAmount' => 0,
                        'remainingAmount' => $sanctioned,
                        'status' => 'sanctioned',
                        'releaseHistory' => [],
                        'createdAt' => new MongoDB\BSON\UTCDateTime(),
                        'updatedAt' => new MongoDB\BSON\UTCDateTime()
                    ];
                    
                    $db->head_allocations->insertOne($allocationDoc);
                    
                    $allocationsArray[] = [
                        'id' => $headId,
                        'headId' => $headId,
                        'headName' => $head['headName'] ?? '',
                        'headType' => $head['headType'] ?? 'recurring',
                        'sanctionedAmount' => $sanctioned,
                        'releasedAmount' => 0,
                        'remainingAmount' => $sanctioned,
                        'status' => 'sanctioned',
                        'releaseHistory' => []
                    ];
                    
                    $totalSanctioned += $sanctioned;
                }
            }
        }
    }
    
    ob_end_clean();
    
    echo json_encode([
        'success' => true,
        'data' => $allocationsArray,
        'totalSanctioned' => $totalSanctioned,
        'totalReleased' => $totalReleased,
        'totalRemaining' => $totalSanctioned - $totalReleased
    ]);
    
} catch (Exception $e) {
    ob_end_clean();
    
    error_log("Fund Allocations API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>