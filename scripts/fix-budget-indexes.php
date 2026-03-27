<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = getMongoDBConnection();
    $collection = $db->budget_requests;

    echo "Adding indexes to budget_requests collection...\n";
    
    $indexes = [
        ['createdAt' => -1],
        ['status' => 1],
        ['currentStage' => 1],
        ['requestNumber' => 1]
    ];

    foreach ($indexes as $key) {
        $indexName = $collection->createIndex($key);
        echo "✅ Created index on: " . json_encode($key) . " (Name: $indexName)\n";
    }

    echo "Done!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
