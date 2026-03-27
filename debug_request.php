<?php
require_once __DIR__ . '/config/database.php';

try {
    $db = getMongoDBConnection();
    $collection = $db->budget_requests;

    $requestNumber = 'BR/2026/0043';
    echo "Finding request $requestNumber...\n";
    $doc = $collection->findOne(['requestNumber' => $requestNumber]);
    
    if (!$doc) {
        echo "Request not found.\n";
        exit;
    }
    
    echo "projectId: " . json_encode($doc['projectId']) . " (Type: " . gettype($doc['projectId']) . ")\n";
    
    if (isset($doc['projectId'])) {
        $pid = $doc['projectId'];
        if (is_string($pid)) {
            try { $pid = new MongoDB\BSON\ObjectId($pid); } catch (Exception $e) {}
        }
        $proj = $db->projects->findOne(['_id' => $pid]);
        if ($proj) {
            echo "Project found:\n";
            echo "  projectName: " . ($proj['projectName'] ?? 'NONE') . "\n";
            echo "  projectEndDate: " . json_encode($proj['projectEndDate'] ?? 'NONE') . "\n";
        } else {
            echo "Project not found for projectId $pid\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
