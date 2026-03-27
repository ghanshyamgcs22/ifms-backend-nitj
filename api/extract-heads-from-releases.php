<?php
/**
 * EXTRACT HEADS FROM RELEASES
 * 
 * This script:
 * 1. Finds all unique heads from fund_releases collection
 * 2. Adds them to project.heads array
 * 3. Then runs migration to build fund_allocations
 * 
 * Run this BEFORE the main migration if projects don't have heads
 */

header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

try {
    $db = getMongoDBConnection();
    
    echo "========================================\n";
    echo "EXTRACTING HEADS FROM RELEASES\n";
    echo "========================================\n\n";
    
    $results = [
        'projects_updated' => 0,
        'heads_extracted' => 0,
        'errors' => []
    ];
    
    // Get all projects
    $projects = $db->projects->find();
    
    foreach ($projects as $project) {
        $projectId = (string) $project['_id'];
        $gpNumber = $project['gpNumber'] ?? 'N/A';
        
        echo "Processing: $gpNumber ($projectId)\n";
        
        // Check if already has heads
        if (isset($project['heads']) && is_array($project['heads']) && count($project['heads']) > 0) {
            echo "  ✅ Already has " . count($project['heads']) . " heads - SKIPPING\n\n";
            continue;
        }
        
        // Get all releases for this project
        $releases = $db->fund_releases->find(['projectId' => $projectId]);
        
        $headsMap = [];
        $releaseCount = 0;
        
        foreach ($releases as $release) {
            $releaseCount++;
            
            if (!isset($release['headwiseReleases']) || !is_array($release['headwiseReleases'])) {
                continue;
            }
            
            foreach ($release['headwiseReleases'] as $headRelease) {
                $headId = $headRelease['id'] ?? null;
                
                if (!$headId) {
                    // Generate ID if missing
                    $headId = (string) new MongoDB\BSON\ObjectId();
                }
                
                // Only store unique heads (by name)
                $headName = $headRelease['headName'] ?? 'Unknown';
                
                if (!isset($headsMap[$headName])) {
                    $headsMap[$headName] = [
                        'id' => $headId,
                        'headId' => $headRelease['headId'] ?? null,
                        'headName' => $headName,
                        'headType' => $headRelease['headType'] ?? 'recurring',
                        'sanctionedAmount' => floatval($headRelease['sanctionedAmount'] ?? 0)
                    ];
                }
            }
        }
        
        if (count($headsMap) === 0) {
            echo "  ⚠️  No releases found - Cannot extract heads\n";
            echo "  ℹ️  You need to manually add heads to this project\n\n";
            $results['errors'][] = "$gpNumber: No releases to extract heads from";
            continue;
        }
        
        $projectHeads = array_values($headsMap);
        
        // Calculate total sanctioned
        $totalSanctioned = array_sum(array_column($projectHeads, 'sanctionedAmount'));
        
        // Update project
        $updateResult = $db->projects->updateOne(
            ['_id' => $project['_id']],
            [
                '$set' => [
                    'heads' => $projectHeads,
                    'totalSanctionedAmount' => $totalSanctioned,
                    'updatedAt' => new MongoDB\BSON\UTCDateTime()
                ]
            ]
        );
        
        if ($updateResult->getModifiedCount() > 0 || $updateResult->getMatchedCount() > 0) {
            echo "  ✅ Added " . count($projectHeads) . " heads from $releaseCount releases\n";
            echo "     Heads: " . implode(", ", array_column($projectHeads, 'headName')) . "\n";
            echo "     Total Sanctioned: ₹" . number_format($totalSanctioned, 2) . "\n\n";
            
            $results['projects_updated']++;
            $results['heads_extracted'] += count($projectHeads);
        }
    }
    
    echo "\n========================================\n";
    echo "EXTRACTION COMPLETED!\n";
    echo "========================================\n\n";
    echo "Summary:\n";
    echo "- Projects Updated: " . $results['projects_updated'] . "\n";
    echo "- Total Heads Extracted: " . $results['heads_extracted'] . "\n";
    
    if (count($results['errors']) > 0) {
        echo "\nProjects that need manual attention:\n";
        foreach ($results['errors'] as $error) {
            echo "- $error\n";
        }
    }
    
    echo "\n✅ Now run migrate-database.php to complete the setup!\n";
    echo "URL: ${import.meta.env.VITE_API_URL}/api/migrate-database.php\n\n";
    
} catch (Exception $e) {
    echo "\n❌ ERROR\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " (Line " . $e->getLine() . ")\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
?>