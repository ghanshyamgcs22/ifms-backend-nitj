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
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method is allowed');
    }
    
    $rawData = file_get_contents('php://input');
    $data = json_decode($rawData, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON');
    }
    
    if (empty($data['projectId']) || empty($data['allocations'])) {
        throw new Exception('Project ID and allocations are required');
    }
    
    $projectId = $data['projectId'];
    $gpNumber = $data['gpNumber'] ?? '';
    $newAllocations = $data['allocations'];
    
    // ============================================================
    // STEP 1: GET PROJECT AND VALIDATE IT EXISTS
    // ============================================================
    $project = $db->projects->findOne([
        '_id' => new MongoDB\BSON\ObjectId($projectId)
    ]);
    
    if (!$project) {
        throw new Exception('Project not found');
    }
    
    // Get FIXED project totals (these cannot change)
    $FIXED_TOTAL_SANCTIONED = floatval($project['totalSanctionedAmount'] ?? 0);
    $FIXED_TOTAL_RELEASED = floatval($project['totalReleasedAmount'] ?? 0);
    
    // ============================================================
    // STEP 2: GET CURRENT ALLOCATIONS FROM DATABASE
    // ============================================================
    $currentAllocations = [];
    $currentAllocsFromDB = $db->head_allocations->find(['projectId' => $projectId]);
    
    foreach ($currentAllocsFromDB as $alloc) {
        $currentAllocations[$alloc['headId']] = [
            'sanctionedAmount' => floatval($alloc['sanctionedAmount'] ?? 0),
            'releasedAmount' => floatval($alloc['releasedAmount'] ?? 0),
            'headName' => $alloc['headName'] ?? ''
        ];
    }
    
    // Fallback to project.heads if not in head_allocations
    if (empty($currentAllocations) && isset($project['heads'])) {
        $heads = $project['heads'];
        if ($heads instanceof MongoDB\Model\BSONArray) {
            $heads = iterator_to_array($heads);
        }
        
        foreach ($heads as $head) {
            if (is_object($head)) {
                $head = (array) $head;
            }
            $headId = $head['id'] ?? $head['headId'];
            if ($headId) {
                $currentAllocations[$headId] = [
                    'sanctionedAmount' => floatval($head['sanctionedAmount'] ?? 0),
                    'releasedAmount' => floatval($head['releasedAmount'] ?? 0),
                    'headName' => $head['headName'] ?? ''
                ];
            }
        }
    }
    
    // ============================================================
    // STEP 3: VALIDATE ALL ALLOCATIONS
    // ============================================================
    $totalNewSanctioned = 0;
    $totalNewReleased = 0;
    $formattedAllocations = [];
    
    foreach ($newAllocations as $alloc) {
        $headId = $alloc['id'];
        $headName = $alloc['headName'] ?? 'Unknown';
        $newSanctioned = floatval($alloc['sanctionedAmount'] ?? 0);
        $newReleased = floatval($alloc['releasedAmount'] ?? 0);
        
        // Get current values from DB
        if (!isset($currentAllocations[$headId])) {
            throw new Exception("Head not found in database: {$headName}");
        }
        
        $currentSanctioned = $currentAllocations[$headId]['sanctionedAmount'];
        $currentReleased = $currentAllocations[$headId]['releasedAmount'];
        
        // ========================================
        // VALIDATION 1: Amounts must be >= 0
        // ========================================
        if ($newSanctioned < 0) {
            throw new Exception("Sanctioned amount cannot be negative for {$headName}");
        }
        
        if ($newReleased < 0) {
            throw new Exception("Released amount cannot be negative for {$headName}");
        }
        
        // ========================================
        // VALIDATION 2: Sanctioned must be > 0
        // ========================================
        if ($newSanctioned <= 0) {
            throw new Exception("Sanctioned amount must be greater than 0 for {$headName}");
        }
        
        // ========================================
        // VALIDATION 3: New sanctioned >= current released
        // (Cannot reduce sanctioned below what's already released)
        // ========================================
        if ($newSanctioned < $currentReleased) {
            throw new Exception(
                "Cannot reduce sanctioned amount for {$headName} below already released amount.\n\n" .
                "Already released: ₹" . number_format($currentReleased, 2) . "\n" .
                "Attempted sanctioned: ₹" . number_format($newSanctioned, 2) . "\n" .
                "Minimum sanctioned allowed: ₹" . number_format($currentReleased, 2) . "\n\n" .
                "Reason: Funds have already been released and cannot be taken back."
            );
        }
        
        // ========================================
        // VALIDATION 4: New released <= new sanctioned
        // ========================================
        if ($newReleased > $newSanctioned) {
            throw new Exception(
                "Released amount for {$headName} (₹" . number_format($newReleased, 2) . ") " .
                "cannot exceed sanctioned amount (₹" . number_format($newSanctioned, 2) . ")"
            );
        }
        
        $totalNewSanctioned += $newSanctioned;
        $totalNewReleased += $newReleased;
        
        $formattedAllocations[] = [
            'id' => $headId,
            'headId' => $alloc['headId'] ?? null,
            'headName' => htmlspecialchars(strip_tags($headName)),
            'headType' => htmlspecialchars(strip_tags($alloc['headType'] ?? '')),
            'sanctionedAmount' => $newSanctioned,
            'releasedAmount' => $newReleased,
            'remainingAmount' => $newSanctioned - $newReleased,
            'status' => $newReleased >= $newSanctioned ? 'fully_released' : 
                       ($newReleased > 0 ? 'partially_released' : 'sanctioned')
        ];
    }
    
    // ========================================
    // VALIDATION 5: Total sanctioned must equal FIXED total
    // ========================================
    if (abs($totalNewSanctioned - $FIXED_TOTAL_SANCTIONED) > 0.01) {
        throw new Exception(
            "Head-wise sanctioned amounts must equal project total sanctioned.\n\n" .
            "Project total sanctioned: ₹" . number_format($FIXED_TOTAL_SANCTIONED, 2) . "\n" .
            "Sum of head-wise sanctioned: ₹" . number_format($totalNewSanctioned, 2) . "\n" .
            "Difference: ₹" . number_format(abs($totalNewSanctioned - $FIXED_TOTAL_SANCTIONED), 2) . "\n\n" .
            "You can redistribute amounts across heads, but the total must remain ₹" . 
            number_format($FIXED_TOTAL_SANCTIONED, 2)
        );
    }
    
    // ========================================
    // VALIDATION 6: Total released cannot exceed FIXED total released
    // ========================================
    if ($totalNewReleased > $FIXED_TOTAL_RELEASED) {
        throw new Exception(
            "Total released cannot exceed project's total released amount.\n\n" .
            "Project total released: ₹" . number_format($FIXED_TOTAL_RELEASED, 2) . "\n" .
            "Sum of head-wise released: ₹" . number_format($totalNewReleased, 2) . "\n" .
            "Excess: ₹" . number_format($totalNewReleased - $FIXED_TOTAL_RELEASED, 2) . "\n\n" .
            "You can only redistribute existing released funds across heads.\n" .
            "To release more funds, use the 'Release Funds' feature."
        );
    }
    
    // ========================================
    // VALIDATION 7: Total released <= total sanctioned
    // ========================================
    if ($totalNewReleased > $totalNewSanctioned) {
        throw new Exception(
            "Total released (₹" . number_format($totalNewReleased, 2) . ") " .
            "cannot exceed total sanctioned (₹" . number_format($totalNewSanctioned, 2) . ")"
        );
    }
    
    // ============================================================
    // STEP 4: ALL VALIDATIONS PASSED - PROCEED WITH UPDATE
    // ============================================================
    
    // Update project document
    $db->projects->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($projectId)],
        [
            '$set' => [
                'heads' => $formattedAllocations,
                'totalAllocatedAmount' => $totalNewSanctioned,
                // Note: totalSanctionedAmount and totalReleasedAmount remain unchanged
                // as they are controlled by other features
                'updatedAt' => new MongoDB\BSON\UTCDateTime()
            ]
        ]
    );
    
    // Update head_allocations collection
    foreach ($formattedAllocations as $alloc) {
        $existingAlloc = $db->head_allocations->findOne([
            'projectId' => $projectId,
            'headId' => $alloc['id']
        ]);
        
        $releaseHistory = $existingAlloc['releaseHistory'] ?? [];
        
        // Track changes in history
        if ($existingAlloc) {
            $headId = $alloc['id'];
            $oldSanctioned = floatval($existingAlloc['sanctionedAmount'] ?? 0);
            $oldReleased = floatval($existingAlloc['releasedAmount'] ?? 0);
            $newSanctioned = $alloc['sanctionedAmount'];
            $newReleased = $alloc['releasedAmount'];
            
            // Track sanctioned changes
            if (abs($newSanctioned - $oldSanctioned) > 0.01) {
                $releaseHistory[] = [
                    'releaseNumber' => 'SANC-ADJ-' . date('Ymd-His'),
                    'letterNumber' => 'Sanctioned Adjustment',
                    'letterDate' => date('Y-m-d'),
                    'releaseAmount' => 0,
                    'releasedAt' => date('Y-m-d H:i:s'),
                    'remarks' => 'Sanctioned amount adjusted from ₹' . number_format($oldSanctioned, 2) . 
                                ' to ₹' . number_format($newSanctioned, 2) . 
                                ' (Change: ' . ($newSanctioned > $oldSanctioned ? '+' : '') . 
                                '₹' . number_format($newSanctioned - $oldSanctioned, 2) . ')'
                ];
            }
            
            // Track released changes
            if (abs($newReleased - $oldReleased) > 0.01) {
                $releaseHistory[] = [
                    'releaseNumber' => 'REL-ADJ-' . date('Ymd-His'),
                    'letterNumber' => 'Released Adjustment',
                    'letterDate' => date('Y-m-d'),
                    'releaseAmount' => $newReleased - $oldReleased,
                    'releasedAt' => date('Y-m-d H:i:s'),
                    'remarks' => 'Released amount adjusted from ₹' . number_format($oldReleased, 2) . 
                                ' to ₹' . number_format($newReleased, 2) . 
                                ' (Change: ' . ($newReleased > $oldReleased ? '+' : '') . 
                                '₹' . number_format($newReleased - $oldReleased, 2) . ')'
                ];
            }
        }
        
        // Upsert head_allocations
        $db->head_allocations->updateOne(
            [
                'projectId' => $projectId,
                'headId' => $alloc['id']
            ],
            [
                '$set' => [
                    'gpNumber' => $gpNumber,
                    'headName' => $alloc['headName'],
                    'headType' => $alloc['headType'],
                    'sanctionedAmount' => $alloc['sanctionedAmount'],
                    'releasedAmount' => $alloc['releasedAmount'],
                    'remainingAmount' => $alloc['remainingAmount'],
                    'status' => $alloc['status'],
                    'releaseHistory' => $releaseHistory,
                    'updatedAt' => new MongoDB\BSON\UTCDateTime()
                ]
            ],
            ['upsert' => true]
        );
    }
    
    // Update fund_allocations (backward compatibility)
    $existingFundAlloc = $db->fund_allocations->findOne(['projectId' => $projectId]);
    
    if ($existingFundAlloc) {
        $db->fund_allocations->updateOne(
            ['projectId' => $projectId],
            [
                '$set' => [
                    'allocations' => $formattedAllocations,
                    'totalAllocated' => $totalNewSanctioned,
                    'totalReleased' => $totalNewReleased,
                    'updatedAt' => new MongoDB\BSON\UTCDateTime()
                ]
            ]
        );
    } else {
        $db->fund_allocations->insertOne([
            'projectId' => $projectId,
            'gpNumber' => $gpNumber,
            'allocations' => $formattedAllocations,
            'totalAllocated' => $totalNewSanctioned,
            'totalReleased' => $totalNewReleased,
            'createdAt' => new MongoDB\BSON\UTCDateTime(),
            'updatedAt' => new MongoDB\BSON\UTCDateTime()
        ]);
    }
    
    ob_end_clean();
    
    echo json_encode([
        'success' => true,
        'message' => 'Allocations updated successfully',
        'data' => [
            'projectId' => $projectId,
            'gpNumber' => $gpNumber,
            'totalSanctioned' => $totalNewSanctioned,
            'totalReleased' => $totalNewReleased,
            'totalRemaining' => $totalNewSanctioned - $totalNewReleased,
            'headsCount' => count($formattedAllocations),
            'fixedTotalSanctioned' => $FIXED_TOTAL_SANCTIONED,
            'fixedTotalReleased' => $FIXED_TOTAL_RELEASED
        ]
    ]);
    
} catch (Exception $e) {
    ob_end_clean();
    
    error_log("Update Allocations API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>