<?php
/**
 * FIX DUPLICATE RELEASE NUMBER ISSUE
 * 
 * This script fixes releases with null or duplicate releaseNumbers
 */

header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

try {
    $db = getMongoDBConnection();
    
    echo "========================================\n";
    echo "FIXING RELEASE NUMBERS\n";
    echo "========================================\n\n";
    
    // Find releases with null releaseNumber
    $nullReleases = $db->fund_releases->find([
        '$or' => [
            ['releaseNumber' => null],
            ['releaseNumber' => ['$exists' => false]]
        ]
    ]);
    
    $nullCount = 0;
    foreach ($nullReleases as $release) {
        $nullCount++;
    }
    
    echo "Found $nullCount releases with null/missing releaseNumber\n\n";
    
    if ($nullCount > 0) {
        echo "Fixing null release numbers...\n";
        
        // Get current counter
        $counter = $db->counters->findOne(['_id' => 'releaseNumber']);
        $currentSeq = $counter ? $counter['seq'] : 0;
        
        $nullReleases->rewind();
        $fixed = 0;
        
        foreach ($nullReleases as $release) {
            $currentSeq++;
            $newReleaseNumber = 'REL-' . str_pad($currentSeq, 4, '0', STR_PAD_LEFT);
            
            $db->fund_releases->updateOne(
                ['_id' => $release['_id']],
                ['$set' => ['releaseNumber' => $newReleaseNumber]]
            );
            
            echo "  ✅ Assigned $newReleaseNumber to release " . (string)$release['_id'] . "\n";
            $fixed++;
        }
        
        // Update counter
        $db->counters->updateOne(
            ['_id' => 'releaseNumber'],
            ['$set' => ['seq' => $currentSeq]],
            ['upsert' => true]
        );
        
        echo "\n✅ Fixed $fixed release numbers\n";
        echo "   Counter updated to: $currentSeq\n\n";
    }
    
    // Now drop and recreate the index
    echo "Recreating releaseNumber index...\n";
    
    try {
        $db->fund_releases->dropIndex('releaseNumber_1');
        echo "  ✅ Dropped old index\n";
    } catch (Exception $e) {
        echo "  ℹ️  Index didn't exist or already dropped\n";
    }
    
    try {
        $db->fund_releases->createIndex(
            ['releaseNumber' => 1],
            [
                'unique' => true,
                'sparse' => true  // Allow missing values but ensure uniqueness for existing ones
            ]
        );
        echo "  ✅ Created new unique index on releaseNumber\n";
    } catch (Exception $e) {
        echo "  ⚠️  Could not create index: " . $e->getMessage() . "\n";
    }
    
    echo "\n========================================\n";
    echo "COMPLETED!\n";
    echo "========================================\n\n";
    
    // Verify
    echo "Verification:\n";
    $totalReleases = $db->fund_releases->countDocuments([]);
    $nullReleases = $db->fund_releases->countDocuments([
        '$or' => [
            ['releaseNumber' => null],
            ['releaseNumber' => ['$exists' => false]]
        ]
    ]);
    
    echo "  Total releases: $totalReleases\n";
    echo "  Releases with null releaseNumber: $nullReleases\n";
    
    if ($nullReleases === 0) {
        echo "\n✅ All release numbers are now valid!\n\n";
    } else {
        echo "\n⚠️  Still have $nullReleases releases with null numbers\n";
        echo "   You may need to manually fix these or delete them.\n\n";
    }
    
} catch (Exception $e) {
    echo "\n❌ ERROR\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " (Line " . $e->getLine() . ")\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
?>