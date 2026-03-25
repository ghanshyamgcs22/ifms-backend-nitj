<?php
require_once __DIR__ . '/config/database.php';

try {
    $db = getMongoDBConnection();
    $collection = $db->budget_requests;

    $id = new MongoDB\BSON\ObjectId('69c0dd4744ae453fcc025ea4');
    echo "Inspecting document $id via aggregation to find long strings...\n";
    
    $pipeline = [
        ['$match' => ['_id' => $id]],
        [
            '$project' => [
                'fields' => [
                    '$map' => [
                        'input' => ['$objectToArray' => '$$ROOT'],
                        'as' => 'field',
                        'in' => [
                            'name' => '$$field.k',
                            'type' => ['$type' => '$$field.v'],
                            'len' => [
                                '$cond' => [
                                    'if' => ['$eq' => [['$type' => '$$field.v'], 'string']],
                                    'then' => ['$strLenBytes' => '$$field.v'],
                                    'else' => 0
                                ]
                            ],
                            'preview' => [
                                '$cond' => [
                                    'if' => ['$eq' => [['$type' => '$$field.v'], 'string']],
                                    'then' => ['$substrBytes' => ['$$field.v', 0, 50]],
                                    'else' => ''
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ];

    $cursor = $collection->aggregate($pipeline);
    
    foreach ($cursor as $doc) {
        foreach ($doc['fields'] as $f) {
            echo "Field: {$f['name']} | Type: {$f['type']} | Len: " . round($f['len'] / 1024, 2) . " KB";
            if ($f['len'] > 102400) {
                echo " !!! LARGE STRING !!! | Preview: {$f['preview']}...";
            }
            echo "\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
