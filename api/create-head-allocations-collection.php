<?php
/**
 * CREATE HEAD-WISE ALLOCATION TRACKING
 * 
 * This creates a new collection: head_allocations
 * Structure: One document per project-head combination
 * Benefits: Fast queries, easy updates, clear cumulative tracking
 */

header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

try {
    $db = getMongoDBConnection();
    
    echo "========================================\n";
    echo "CREATING HEAD ALLOCATION TRACKING\n";
    echo "========================================\n\n";
    
    // Step 1: Create head_allocations collection with indexes
    echo "STEP 1: Creating Collection & Indexes\n";
    echo "-------------------------------------\n";
    
    try {
        $db->createCollection('head_allocations');
        echo "  ✅ Created head_allocations collection\n";
    } catch (Exception $e) {
        echo "  ℹ️  Collection may already exist\n";
    }
    
    // Create indexes
    $db->head_allocations->createIndex(['projectId' => 1, 'headId' => 1], ['unique' => true]);
    echo "  ✅ Created unique index on projectId + headId\n";
    
    $db->head_allocations->createIndex(['projectId' => 1]);
    echo "  ✅ Created index on projectId\n";
    
    echo "\n";
    
    // Step 2: Populate from existing projects
    echo "STEP 2: Populating from Projects\n";
    echo "-------------------------------------\n";
    
    $projects = $db->projects->find();
    $created = 0;
    $skipped = 0;
    
    foreach ($projects as $project) {
        $projectId = (string) $project['_id'];
        $gpNumber = $project['gpNumber'] ?? 'N/A';
        
        echo "Processing: $gpNumber\n";
        
        // Get project heads
        $projectHeads = [];
        if (isset($project['heads'])) {
            $heads = $project['heads'];
            if ($heads instanceof MongoDB\Model\BSONArray) {
                $heads = iterator_to_array($heads);
            }
            if (is_array($heads)) {
                $projectHeads = $heads;
            }
        }
        
        if (empty($projectHeads)) {
            echo "  ⚠️  No heads - skipping\n";
            $skipped++;
            continue;
        }
        
        // For each head, create or update allocation record
        foreach ($projectHeads as $head) {
            if (is_object($head)) {
                $head = (array) $head;
            }
            
            $headId = $head['id'] ?? $head['headId'] ?? (string) new MongoDB\BSON\ObjectId();
            $headName = $head['headName'] ?? 'Unknown';
            $sanctioned = floatval($head['sanctionedAmount'] ?? 0);
            
            // Calculate released amount from fund_releases
            $releasedAmount = 0;
            $releaseHistory = [];
            
            $releases = $db->fund_releases->find(['projectId' => $projectId]);
            
            foreach ($releases as $release) {
                if (!isset($release['headwiseReleases'])) continue;
                
                $headReleases = $release['headwiseReleases'];
                if ($headReleases instanceof MongoDB\Model\BSONArray) {
                    $headReleases = iterator_to_array($headReleases);
                }
                
                foreach ($headReleases as $hr) {
                    if (is_object($hr)) $hr = (array) $hr;
                    
                    if (($hr['id'] ?? null) === $headId) {
                        $amt = floatval($hr['releaseAmount'] ?? 0);
                        $releasedAmount += $amt;
                        
                        // Add to history
                        $letterDate = null;
                        if (isset($release['letterDate']) && $release['letterDate'] instanceof MongoDB\BSON\UTCDateTime) {
                            $letterDate = $release['letterDate']->toDateTime()->format('Y-m-d');
                        }
                        
                        $releasedAt = null;
                        if (isset($release['releasedAt']) && $release['releasedAt'] instanceof MongoDB\BSON\UTCDateTime) {
                            $releasedAt = $release['releasedAt']->toDateTime()->format('Y-m-d H:i:s');
                        }
                        
                        $releaseHistory[] = [
                            'releaseNumber' => $release['releaseNumber'] ?? '',
                            'letterNumber' => $release['letterNumber'] ?? '',
                            'letterDate' => $letterDate,
                            'releaseAmount' => $amt,
                            'releasedAt' => $releasedAt,
                            'remarks' => $release['remarks'] ?? ''
                        ];
                    }
                }
            }
            
            // Create/update head allocation record
            $allocationDoc = [
                'projectId' => $projectId,
                'gpNumber' => $gpNumber,
                'headId' => $headId,
                'headName' => $headName,
                'headType' => $head['headType'] ?? 'recurring',
                'sanctionedAmount' => $sanctioned,
                'releasedAmount' => $releasedAmount,
                'remainingAmount' => $sanctioned - $releasedAmount,
                'status' => $releasedAmount >= $sanctioned ? 'fully_released' : 
                           ($releasedAmount > 0 ? 'partially_released' : 'sanctioned'),
                'releaseHistory' => $releaseHistory,
                'updatedAt' => new MongoDB\BSON\UTCDateTime()
            ];
            
            // Use upsert to create or update
            $db->head_allocations->updateOne(
                [
                    'projectId' => $projectId,
                    'headId' => $headId
                ],
                ['$set' => $allocationDoc],
                ['upsert' => true]
            );
            
            $created++;
        }
        
        echo "  ✅ Created/updated " . count($projectHeads) . " head allocations\n";
        echo "     Released: ₹" . number_format($releasedAmount, 2) . "\n";
    }
    
    echo "\n";
    
    // Step 3: Verify
    echo "STEP 3: Verification\n";
    echo "-------------------------------------\n";
    
    $totalAllocations = $db->head_allocations->countDocuments([]);
    $withReleases = $db->head_allocations->countDocuments(['releasedAmount' => ['$gt' => 0]]);
    
    echo "  Total head allocations: $totalAllocations\n";
    echo "  Heads with releases: $withReleases\n";
    
    // Sample data
    $sample = $db->head_allocations->findOne(['releasedAmount' => ['$gt' => 0]]);
    if ($sample) {
        echo "\n  Sample allocation:\n";
        echo "    Project: " . ($sample['gpNumber'] ?? 'N/A') . "\n";
        echo "    Head: " . ($sample['headName'] ?? 'N/A') . "\n";
        echo "    Sanctioned: ₹" . number_format($sample['sanctionedAmount'] ?? 0, 2) . "\n";
        echo "    Released: ₹" . number_format($sample['releasedAmount'] ?? 0, 2) . "\n";
        echo "    History entries: " . count($sample['releaseHistory'] ?? []) . "\n";
    }
    
    echo "\n========================================\n";
    echo "COLLECTION CREATED SUCCESSFULLY!\n";
    echo "========================================\n\n";
    
    echo "Summary:\n";
    echo "  - Head allocations created: $created\n";
    echo "  - Projects skipped (no heads): $skipped\n";
    echo "  - Total in collection: $totalAllocations\n\n";
    
    echo "✅ New collection 'head_allocations' is ready!\n";
    echo "   Now update your API to use this collection.\n\n";
    
} catch (Exception $e) {
    echo "\n❌ ERROR\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " (Line " . $e->getLine() . ")\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
?>