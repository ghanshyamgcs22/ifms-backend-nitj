<?php
// api/sendback-request.php
// Universal send-back handler for the DRC chain
//
// drc_rc   sends back to → drc_office
// drc      sends back to → drc_rc
// director sends back to → drc

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit();
}

$input      = json_decode(file_get_contents('php://input'), true);
$requestId  = $input['requestId']  ?? '';
$remarks    = trim($input['remarks'] ?? '');
$sendBackTo = $input['sendBackTo'] ?? '';   // 'drc_office' | 'drc_rc' | 'drc'
$sentBackBy = $input['sentBackBy'] ?? 'Unknown';

if (!$requestId || !$sendBackTo) {
    echo json_encode(['success' => false, 'message' => 'requestId and sendBackTo required']); exit();
}
if (empty($remarks)) {
    echo json_encode(['success' => false, 'message' => 'Remarks are required for send-back']); exit();
}

// Route config: sendBackTo → [requiredCurrentStage, allowedStatuses[], newStatus, remarkField, historyStage]
$routes = [
    'drc_office' => [
        'stage'           => 'drc_rc',
        'allowedStatuses' => ['drc_office_forwarded', 'sent_back_to_drc_rc'],
        'newStatus'       => 'sent_back_to_drc_office',
        'remarkField'     => 'drcRcRemarks',
        'historyStage'    => 'drc_rc',
    ],
    'drc_rc' => [
        'stage'           => 'drc',
        'allowedStatuses' => ['drc_rc_forwarded', 'sent_back_to_drc'],
        'newStatus'       => 'sent_back_to_drc_rc',
        'remarkField'     => 'drcRemarks',
        'historyStage'    => 'drc',
    ],
    'drc' => [
        'stage'           => 'director',
        'allowedStatuses' => ['drc_forwarded'],
        'newStatus'       => 'sent_back_to_drc',
        'remarkField'     => 'directorRemarks',
        'historyStage'    => 'director',
    ],
];

if (!array_key_exists($sendBackTo, $routes)) {
    echo json_encode(['success' => false, 'message' => "Invalid sendBackTo: $sendBackTo"]); exit();
}

$route = $routes[$sendBackTo];

try {
    $db  = getMongoDBConnection();
    $req = $db->budget_requests->findOne(['_id' => new MongoDB\BSON\ObjectId($requestId)]);

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Request not found']); exit();
    }
    if ($req['currentStage'] !== $route['stage']) {
        echo json_encode(['success' => false, 'message' => "Request is not at {$route['stage']} stage"]); exit();
    }
    if (!in_array($req['status'], $route['allowedStatuses'])) {
        echo json_encode(['success' => false, 'message' => "Invalid status for send-back from {$route['stage']}"]); exit();
    }

    $now     = new MongoDB\BSON\UTCDateTime();
    $history = isset($req['approvalHistory']) ? iterator_to_array($req['approvalHistory']) : [];
    $history[] = [
        'stage'     => $route['historyStage'],
        'action'    => 'sent_back',
        'by'        => $sentBackBy,
        'timestamp' => date('c'),
        'remarks'   => $remarks,
    ];

    $db->budget_requests->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($requestId)],
        ['$set' => [
            'status'              => $route['newStatus'],
            'currentStage'        => $sendBackTo,
            $route['remarkField'] => $remarks,
            'sentBackAt'          => $now,
            'approvalHistory'     => $history,
            'updatedAt'           => $now,
        ]]
    );

    $labels = ['drc_office' => 'DRC Office', 'drc_rc' => 'DRC (R&C)', 'drc' => 'DRC'];

    echo json_encode([
        'success' => true,
        'message' => "Request sent back to {$labels[$sendBackTo]} for re-evaluation.",
        'data'    => ['status' => $route['newStatus'], 'currentStage' => $sendBackTo],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>