<?php
// api/drc-forward-director.php
// DRC → forward to Director
// stage: drc → director  |  status: drc_rc_forwarded OR sent_back_to_drc → drc_forwarded

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit();
}

$input     = json_decode(file_get_contents('php://input'), true);
$requestId = $input['requestId']   ?? '';
$remarks   = $input['remarks']     ?? '';
$by        = $input['forwardedBy'] ?? 'DRC';

if (!$requestId) {
    echo json_encode(['success' => false, 'message' => 'requestId required']); exit();
}

try {
    $db  = getMongoDBConnection();
    $req = $db->budget_requests->findOne(['_id' => new MongoDB\BSON\ObjectId($requestId)]);

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Request not found']); exit();
    }
    if ($req['currentStage'] !== 'drc') {
        echo json_encode(['success' => false, 'message' => 'Request is not at DRC stage']); exit();
    }

    $allowedStatuses = ['drc_rc_forwarded', 'sent_back_to_drc'];
    if (!in_array($req['status'], $allowedStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status for DRC action']); exit();
    }

    $now     = new MongoDB\BSON\UTCDateTime();
    $history = isset($req['approvalHistory']) ? iterator_to_array($req['approvalHistory']) : [];
    $history[] = [
        'stage'     => 'drc',
        'action'    => 'forwarded',
        'by'        => $by,
        'timestamp' => date('c'),
        'remarks'   => $remarks,
    ];

    $db->budget_requests->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($requestId)],
        ['$set' => [
            'status'          => 'drc_forwarded',
            'currentStage'    => 'director',
            'drcRemarks'      => $remarks,
            'drcForwardedAt'  => $now,
            'approvalHistory' => $history,
            'updatedAt'       => $now,
        ]]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Forwarded to Director successfully.',
        'data'    => ['status' => 'drc_forwarded', 'currentStage' => 'director'],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>