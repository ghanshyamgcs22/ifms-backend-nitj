<?php
/**
 * Verify Budget Request API (Admin)
 * 
 * Admin approves or rejects a budget request
 * On approval, updates:
 * - head_allocations.bookedAmount
 * - projects.amountBookedByPI
 * 
 * Method: POST
 * Body: {
 *   requestId, action ("approve" or "reject"), 
 *   adminName, adminRemarks
 * }
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

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
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method is allowed');
    }
    
    $rawData = file_get_contents('php://input');
    $data = json_decode($rawData, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON');
    }
    
    // Validate required fields
    if (empty($data['requestId'])) {
        throw new Exception('Request ID is required');
    }
    
    if (empty($data['action']) || !in_array($data['action'], ['approve', 'reject'])) {
        throw new Exception('Valid action (approve or reject) is required');
    }
    
    if (empty($data['adminName'])) {
        throw new Exception('Admin name is required');
    }
    
    $requestId = $data['requestId'];
    $action = $data['action'];
    $adminName = htmlspecialchars(strip_tags($data['adminName']));
    $adminRemarks = htmlspecialchars(strip_tags($data['adminRemarks'] ?? ''));
    
    // Get the budget request
    $request = $db->budget_requests->findOne(
        ['_id' => new \MongoDB\BSON\ObjectId($requestId)],
        ['projection' => ['quotation' => 0]]
    );
    
    if (!$request) {
        throw new Exception('Budget request not found');
    }
    
    // Check if already processed
    if ($request['status'] !== 'pending_admin_verification') {
        throw new Exception('This request has already been processed');
    }
    
    $projectId = $request['projectId'];
    $headId = $request['headId'];
    $requestedAmount = floatval($request['requestedAmount']);
    
    // Get current head allocation
    $headAllocation = $db->head_allocations->findOne([
        'projectId' => $projectId,
        'headId' => $headId
    ]);
    
    if (!$headAllocation) {
        throw new Exception('Head allocation not found');
    }
    
    $currentReleased = floatval($headAllocation['releasedAmount'] ?? 0);
    $currentBooked = floatval($headAllocation['bookedAmount'] ?? 0);
    $currentAvailable = $currentReleased - $currentBooked;
    
    if ($action === 'approve') {
        // Validate amount is still available
        if ($requestedAmount > $currentAvailable) {
            throw new Exception(
                "Insufficient balance. Available: ₹" . number_format($currentAvailable, 2) . 
                ", Requested: ₹" . number_format($requestedAmount, 2)
            );
        }
        
        // Update head_allocations - increase bookedAmount
        $newBookedAmount = $currentBooked + $requestedAmount;
        
        $db->head_allocations->updateOne(
            [
                'projectId' => $projectId,
                'headId' => $headId
            ],
            [
                '$set' => [
                    'bookedAmount' => $newBookedAmount,
                    'updatedAt' => new \MongoDB\BSON\UTCDateTime()
                ]
            ]
        );
        
        // Update project - increase amountBookedByPI
        $project = $db->projects->findOne([
            '_id' => new \MongoDB\BSON\ObjectId($projectId)
        ]);
        
        $currentProjectBooked = floatval($project['amountBookedByPI'] ?? 0);
        $newProjectBooked = $currentProjectBooked + $requestedAmount;
        
        $db->projects->updateOne(
            ['_id' => new ObjectId($projectId)],
            [
                '$set' => [
                    'amountBookedByPI' => $newProjectBooked,
                    'updatedAt' => new \MongoDB\BSON\UTCDateTime()
                ]
            ]
        );
        
        $newStatus = 'approved';
        $statusNote = "Approved by {$adminName}";
        
    } else {
        // Rejected
        $newStatus = 'rejected';
        $statusNote = "Rejected by {$adminName}" . ($adminRemarks ? ": {$adminRemarks}" : "");
    }
    
    // Update budget request status
    $statusHistory = $request['statusHistory'];
    if ($statusHistory instanceof MongoDB\Model\BSONArray) {
        $statusHistory = iterator_to_array($statusHistory);
    }
    
    $statusHistory[] = [
        'status' => $newStatus,
        'timestamp' => new UTCDateTime(),
        'note' => $statusNote
    ];
    
    $db->budget_requests->updateOne(
        ['_id' => new ObjectId($requestId)],
        [
            '$set' => [
                'status' => $newStatus,
                'adminVerifiedBy' => $adminName,
                'adminVerifiedAt' => new UTCDateTime(),
                'adminRemarks' => $adminRemarks,
                'statusHistory' => $statusHistory,
                'updatedAt' => new UTCDateTime()
            ]
        ]
    );
    
    ob_end_clean();
    
    $message = $action === 'approve' 
        ? "Budget request approved successfully. Amount ₹" . number_format($requestedAmount, 2) . " has been booked."
        : "Budget request rejected.";
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => [
            'requestId' => $requestId,
            'requestNumber' => $request['requestNumber'],
            'action' => $action,
            'status' => $newStatus,
            'amount' => $requestedAmount
        ]
    ]);
    
} catch (Exception $e) {
    ob_end_clean();
    
    error_log("Verify Budget Request Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>