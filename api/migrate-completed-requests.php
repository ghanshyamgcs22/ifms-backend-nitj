<?php
// migrate-completed-requests.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/database.php';

use MongoDB\BSON\UTCDateTime;

try {
    $db = getMongoDBConnection();
    $budgetRequestsCollection = $db->budget_requests;
    $releasedFundsCollection = $db->released_funds;
    
    echo "Starting migration...\n\n";
    
    // Step 1: Clear existing released_funds collection (optional)
    echo "Step 1: Clearing existing released_funds collection...\n";
    $deleteResult = $releasedFundsCollection->deleteMany([]);
    echo "Deleted {$deleteResult->getDeletedCount()} existing records\n\n";
    
    // Step 2: Find all completed budget requests
    echo "Step 2: Finding completed budget requests...\n";
    $completedRequests = $budgetRequestsCollection->find([
        'status' => 'approved',
        'currentStage' => 'completed'
    ])->toArray();
    
    echo "Found " . count($completedRequests) . " completed requests\n\n";
    
    // Step 3: Insert each completed request into released_funds
    $insertedCount = 0;
    $skippedCount = 0;
    
    foreach ($completedRequests as $request) {
        try {
            // Check if already exists
            $exists = $releasedFundsCollection->findOne([
                'gpNumber' => $request->gpNumber
            ]);
            
            if ($exists) {
                echo "⚠️  Skipping {$request->gpNumber} - already exists\n";
                $skippedCount++;
                continue;
            }
            
            // Generate default letter number if not present
            $letterDate = !empty($request->letterDate) ? $request->letterDate : date('Y-m-d');
            $letterNumber = !empty($request->letterNumber) ? $request->letterNumber : 'FIN/REL/' . date('Y') . '/' . str_pad($insertedCount + 1, 3, '0', STR_PAD_LEFT);
            
            // Create released_funds document with ALL required fields
            $releaseData = [
                // Required fields
                'gpNumber' => $request->gpNumber,
                'projectTitle' => $request->projectTitle ?? 'Untitled Project',
                'piName' => $request->piName ?? 'Unknown PI',
                'piEmail' => $request->piEmail ?? '',
                'department' => $request->department ?? 'Unknown Department',
                'releaseAmount' => $request->amount ?? 0, // Use sanctioned amount as initial release
                'letterDate' => $letterDate,
                'letterNumber' => $letterNumber,
                'releasedBy' => $request->releasedBy ?? 'Finance Officer',
                'releasedAt' => $request->ao2ApprovedAt ?? new UTCDateTime(),
                
                // Optional fields
                'purpose' => $request->purpose ?? '',
                'description' => $request->description ?? '',
                'invoiceNumber' => $request->invoiceNumber ?? '',
                
                // Financial tracking
                'sanctionedAmount' => $request->amount ?? 0,
                'previouslyReleased' => 0,
                'totalReleasedAfter' => $request->amount ?? 0, // Full amount released
                'remainingAmount' => 0, // No remaining amount after full release
                
                // Approval information
                'arApprovedAt' => $request->arApprovedAt ?? null,
                'drApprovedAt' => $request->drApprovedAt ?? null,
                'aoApprovedAt' => $request->aoApprovedAt ?? null,
                'ao2ApprovedAt' => $request->ao2ApprovedAt ?? null,
                
                // Timestamps
                'createdAt' => $request->createdAt ?? new UTCDateTime(),
                'updatedAt' => new UTCDateTime(),
                
                // Remarks
                'remarks' => 'Full amount released - Migrated from completed budget request',
                'arRemarks' => $request->arRemarks ?? '',
                'drRemarks' => $request->drRemarks ?? '',
                'aoRemarks' => $request->aoRemarks ?? '',
                'ao2Remarks' => $request->ao2Remarks ?? '',
                
                // Reference
                'budgetRequestId' => (string)$request->_id,
                'projectType' => $request->projectType ?? ''
            ];
            
            // Insert into released_funds
            $result = $releasedFundsCollection->insertOne($releaseData);
            
            if ($result->getInsertedId()) {
                echo "✅ Migrated: {$request->gpNumber} - {$request->projectTitle} (Letter: {$letterNumber})\n";
                $insertedCount++;
            } else {
                echo "❌ Failed to migrate: {$request->gpNumber}\n";
            }
            
        } catch (Exception $e) {
            echo "❌ Error migrating {$request->gpNumber}: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "Migration Summary:\n";
    echo str_repeat("=", 50) . "\n";
    echo "Total completed requests found: " . count($completedRequests) . "\n";
    echo "Successfully migrated: {$insertedCount}\n";
    echo "Skipped (already exists): {$skippedCount}\n";
    echo "Failed: " . (count($completedRequests) - $insertedCount - $skippedCount) . "\n";
    echo str_repeat("=", 50) . "\n\n";
    
    echo "✅ Migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>