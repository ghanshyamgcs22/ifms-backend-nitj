<?php
/**
 * Get Release History API
 * Fetches head-wise release history from fund_releases collection
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

$db = getMongoDBConnection();

try {
    $projectId = $_GET['projectId'] ?? null;
    $headId = $_GET['headId'] ?? null; // Optional: filter by specific head
    
    if (!$projectId) {
        throw new Exception('projectId parameter is required');
    }
    
    // Build query
    $query = ['projectId' => $projectId];
    
    // Fetch all releases for this project
    $releases = $db->fund_releases->find(
        $query,
        ['sort' => ['releasedAt' => -1]] // Newest first
    );
    
    $releasesArray = iterator_to_array($releases);
    
    // If filtering by head, filter the results
    $formattedReleases = [];
    
    foreach ($releasesArray as $release) {
        $releaseData = [
            'id' => (string) $release['_id'],
            'releaseNumber' => $release['releaseNumber'] ?? '',
            'gpNumber' => $release['gpNumber'] ?? '',
            'letterNumber' => $release['letterNumber'] ?? '',
            'letterDate' => $release['letterDate'] ? $release['letterDate']->toDateTime()->format('Y-m-d') : null,
            'remarks' => $release['remarks'] ?? '',
            'totalReleaseAmount' => floatval($release['totalReleaseAmount'] ?? 0),
            'releasedBy' => $release['releasedBy'] ?? '',
            'releasedAt' => $release['releasedAt'] ? $release['releasedAt']->toDateTime()->format('Y-m-d H:i:s') : null,
            'headwiseReleases' => []
        ];
        
        // Format head-wise releases
        if (isset($release['headwiseReleases']) && is_array($release['headwiseReleases'])) {
            foreach ($release['headwiseReleases'] as $headRelease) {
                // If filtering by head, only include matching heads
                if ($headId && isset($headRelease['id']) && $headRelease['id'] !== $headId) {
                    continue;
                }
                
                $releaseData['headwiseReleases'][] = [
                    'id' => $headRelease['id'] ?? null,
                    'headId' => $headRelease['headId'] ?? null,
                    'headName' => $headRelease['headName'] ?? '',
                    'headType' => $headRelease['headType'] ?? '',
                    'sanctionedAmount' => floatval($headRelease['sanctionedAmount'] ?? 0),
                    'previouslyReleased' => floatval($headRelease['previouslyReleased'] ?? 0),
                    'releaseAmount' => floatval($headRelease['releaseAmount'] ?? 0),
                    'newTotalReleased' => floatval($headRelease['newTotalReleased'] ?? 0)
                ];
            }
        }
        
        // If filtering by head and no matching heads found, skip this release
        if ($headId && count($releaseData['headwiseReleases']) === 0) {
            continue;
        }
        
        $formattedReleases[] = $releaseData;
    }
    
    // Get summary statistics
    $totalReleases = count($formattedReleases);
    $totalAmountReleased = array_sum(array_column($formattedReleases, 'totalReleaseAmount'));
    
    // Get unique heads involved in releases
    $headsInvolved = [];
    foreach ($formattedReleases as $release) {
        foreach ($release['headwiseReleases'] as $headRelease) {
            $headName = $headRelease['headName'];
            if (!isset($headsInvolved[$headName])) {
                $headsInvolved[$headName] = [
                    'headName' => $headName,
                    'headType' => $headRelease['headType'],
                    'totalReleased' => 0,
                    'releaseCount' => 0
                ];
            }
            $headsInvolved[$headName]['totalReleased'] += $headRelease['releaseAmount'];
            $headsInvolved[$headName]['releaseCount']++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => $formattedReleases,
        'summary' => [
            'totalReleases' => $totalReleases,
            'totalAmountReleased' => $totalAmountReleased,
            'headsInvolved' => array_values($headsInvolved)
        ],
        'filters' => [
            'projectId' => $projectId,
            'headId' => $headId
        ]
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Get Release History Error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>