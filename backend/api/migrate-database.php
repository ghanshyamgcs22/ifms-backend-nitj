<?php
/**
 * DATABASE MIGRATION SCRIPT
 * 
 * This script rebuilds fund_allocations collection properly by:
 * 1. Taking project.heads as the source of sanctioned amounts
 * 2. Calculating cumulative released amounts from fund_releases collection
 * 3. Creating/updating proper fund_allocations documents with allocations array populated
 * 
 * RUN THIS ONCE TO FIX ALL EXISTING DATA
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

try {
    $db = getMongoDBConnection();
    
    echo "Starting migration...\n\n";
    
    $migrationResults = [
        'projects_processed' => 0,
        'allocations_created' => 0,
        'allocations_updated' => 0,
        'heads_processed' => 0,
        'errors' => []
    ];
    
    // ============================================================================
    // STEP 1: Get all projects
    // ============================================================================
    
    $projects = $db->projects->find();
    
    foreach ($projects as $project) {
        $projectId = (string) $project['_id'];
        $gpNumber = $project['gpNumber'] ?? '';
        
        echo "Processing project: $gpNumber ($projectId)\n";
        
        // Check if project has heads
        if (!isset($project['heads']) || !is_array($project['heads']) || count($project['heads']) === 0) {
            echo "  ⚠️  Project has no heads array - SKIPPING\n\n";
            $migrationResults['errors'][] = "$gpNumber: No heads defined in project";
            continue;
        }
        
        // ========================================================================
        // STEP 2: Build head-wise data structure
        // ========================================================================
        
        $headsData = [];
        
        // Initialize from project.heads with sanctioned amounts
        foreach ($project['heads'] as $head) {
            $headId = $head['id'] ?? $head['headId'] ?? (string) new MongoDB\BSON\ObjectId();
            
            $headsData[$headId] = [
                'id' => $headId,
                'headId' => $head['headId'] ?? null,
                'headName' => $head['headName'] ?? '',
                'headType' => $head['headType'] ?? '',
                'sanctionedAmount' => floatval($head['sanctionedAmount'] ?? 0),
                'releasedAmount' => 0, // Will calculate from releases
                'remainingAmount' => floatval($head['sanctionedAmount'] ?? 0),
                'status' => 'sanctioned'
            ];
        }
        
        // ========================================================================
        // STEP 3: Calculate cumulative released amounts from fund_releases
        // ========================================================================
        
        $releases = $db->fund_releases->find(['projectId' => $projectId]);
        
        foreach ($releases as $release) {
            if (!isset($release['headwiseReleases']) || !is_array($release['headwiseReleases'])) {
                continue;
            }
            
            foreach ($release['headwiseReleases'] as $headRelease) {
                $headId = $headRelease['id'] ?? null;
                
                if (!$headId) {
                    continue;
                }
                
                // If this head exists in our structure, add the release amount
                if (isset($headsData[$headId])) {
                    $releaseAmount = floatval($headRelease['releaseAmount'] ?? 0);
                    $headsData[$headId]['releasedAmount'] += $releaseAmount;
                } else {
                    // Head was in releases but not in project.heads - add it
                    $headsData[$headId] = [
                        'id' => $headId,
                        'headId' => $headRelease['headId'] ?? null,
                        'headName' => $headRelease['headName'] ?? 'Unknown',
                        'headType' => $headRelease['headType'] ?? '',
                        'sanctionedAmount' => floatval($headRelease['sanctionedAmount'] ?? 0),
                        'releasedAmount' => floatval($headRelease['releaseAmount'] ?? 0),
                        'remainingAmount' => 0,
                        'status' => 'partially_released'
                    ];
                }
            }
        }
        
        // ========================================================================
        // STEP 4: Finalize calculations
        // ========================================================================
        
        $formattedAllocations = [];
        $totalAllocated = 0;
        $totalReleased = 0;
        
        foreach ($headsData as $headData) {
            // Recalculate remaining and status
            $sanctioned = $headData['sanctionedAmount'];
            $released = $headData['releasedAmount'];
            $remaining = $sanctioned - $released;
            
            // Determine status
            if ($released >= $sanctioned && $sanctioned > 0) {
                $status = 'fully_released';
            } elseif ($released > 0) {
                $status = 'partially_released';
            } else {
                $status = 'sanctioned';
            }
            
            $formattedAllocations[] = [
                'id' => $headData['id'],
                'headId' => $headData['headId'],
                'headName' => $headData['headName'],
                'headType' => $headData['headType'],
                'sanctionedAmount' => $sanctioned,
                'releasedAmount' => $released,
                'remainingAmount' => $remaining,
                'timePeriod' => '1 Year',
                'bankDetails' => 'Canara Bank',
                'status' => $status
            ];
            
            $totalAllocated += $sanctioned;
            $totalReleased += $released;
            $migrationResults['heads_processed']++;
        }
        
        // ========================================================================
        // STEP 5: Update or create fund_allocations document
        // ========================================================================
        
        $existingAlloc = $db->fund_allocations->findOne(['projectId' => $projectId]);
        
        if ($existingAlloc) {
            // Update existing
            $result = $db->fund_allocations->updateOne(
                ['_id' => $existingAlloc['_id']],
                [
                    '$set' => [
                        'allocations' => $formattedAllocations,
                        'totalAllocated' => $totalAllocated,
                        'totalReleased' => $totalReleased,
                        'updatedAt' => new MongoDB\BSON\UTCDateTime(),
                        'migratedAt' => new MongoDB\BSON\UTCDateTime()
                    ]
                ]
            );
            
            echo "  ✅ Updated fund_allocations with " . count($formattedAllocations) . " heads\n";
            echo "     Total Allocated: ₹" . number_format($totalAllocated, 2) . "\n";
            echo "     Total Released: ₹" . number_format($totalReleased, 2) . "\n\n";
            
            $migrationResults['allocations_updated']++;
        } else {
            // Create new
            $newDoc = [
                'projectId' => $projectId,
                'gpNumber' => $gpNumber,
                'allocations' => $formattedAllocations,
                'totalAllocated' => $totalAllocated,
                'totalReleased' => $totalReleased,
                'createdAt' => new MongoDB\BSON\UTCDateTime(),
                'updatedAt' => new MongoDB\BSON\UTCDateTime(),
                'migratedAt' => new MongoDB\BSON\UTCDateTime()
            ];
            
            $result = $db->fund_allocations->insertOne($newDoc);
            
            echo "  ✅ Created fund_allocations with " . count($formattedAllocations) . " heads\n";
            echo "     Total Allocated: ₹" . number_format($totalAllocated, 2) . "\n";
            echo "     Total Released: ₹" . number_format($totalReleased, 2) . "\n\n";
            
            $migrationResults['allocations_created']++;
        }
        
        // ========================================================================
        // STEP 6: Also ensure project.heads is properly set
        // ========================================================================
        
        $projectHeads = array_map(function($alloc) {
            return [
                'id' => $alloc['id'],
                'headId' => $alloc['headId'],
                'headName' => $alloc['headName'],
                'headType' => $alloc['headType'],
                'sanctionedAmount' => $alloc['sanctionedAmount']
            ];
        }, $formattedAllocations);
        
        $db->projects->updateOne(
            ['_id' => $project['_id']],
            [
                '$set' => [
                    'heads' => $projectHeads,
                    'totalReleasedAmount' => $totalReleased,
                    'updatedAt' => new MongoDB\BSON\UTCDateTime()
                ]
            ]
        );
        
        $migrationResults['projects_processed']++;
    }
    
    // ============================================================================
    // FINAL SUMMARY
    // ============================================================================
    
    echo "\n========================================\n";
    echo "MIGRATION COMPLETED SUCCESSFULLY!\n";
    echo "========================================\n\n";
    echo "Summary:\n";
    echo "- Projects Processed: " . $migrationResults['projects_processed'] . "\n";
    echo "- Allocations Created: " . $migrationResults['allocations_created'] . "\n";
    echo "- Allocations Updated: " . $migrationResults['allocations_updated'] . "\n";
    echo "- Total Heads Processed: " . $migrationResults['heads_processed'] . "\n";
    
    if (count($migrationResults['errors']) > 0) {
        echo "\nErrors/Warnings:\n";
        foreach ($migrationResults['errors'] as $error) {
            echo "- $error\n";
        }
    }
    
    echo "\n✅ All data migrated successfully!\n";
    echo "You can now use the release funds feature without errors.\n\n";
    
    echo json_encode([
        'success' => true,
        'message' => 'Migration completed successfully',
        'results' => $migrationResults
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo "\n❌ MIGRATION FAILED\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
?>