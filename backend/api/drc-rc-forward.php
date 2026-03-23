<?php
// api/drc-rc-forward.php
// DRC (R&C) → forward to DRC
// stage: drc_rc → drc  |  status: drc_office_forwarded OR sent_back_to_drc_rc → drc_rc_forwarded

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
$requestId = $input['requestId'] ?? '';
$remarks   = $input['remarks']   ?? '';
$by        = $input['actionBy']  ?? 'DRC R&C';

if (!$requestId) {
    echo json_encode(['success' => false, 'message' => 'requestId required']); exit();
}

try {
    $db  = getMongoDBConnection();
    $req = $db->budget_requests->findOne(['_id' => new MongoDB\BSON\ObjectId($requestId)]);

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Request not found']); exit();
    }
    if ($req['currentStage'] !== 'drc_rc') {
        echo json_encode(['success' => false, 'message' => 'Request is not at DRC (R&C) stage']); exit();
    }

    $allowedStatuses = ['drc_office_forwarded', 'sent_back_to_drc_rc'];
    if (!in_array($req['status'], $allowedStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status for DRC R&C action']); exit();
    }

    $now     = new MongoDB\BSON\UTCDateTime();
    $history = isset($req['approvalHistory']) ? iterator_to_array($req['approvalHistory']) : [];
    $history[] = [
        'stage'     => 'drc_rc',
        'action'    => 'forwarded',
        'by'        => $by,
        'timestamp' => date('c'),
        'remarks'   => $remarks,
    ];

    $db->budget_requests->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($requestId)],
        ['$set' => [
            'status'           => 'drc_rc_forwarded',
            'currentStage'     => 'drc',
            'drcRcRemarks'     => $remarks,
            'drcRcForwardedAt' => $now,
            'approvalHistory'  => $history,
            'updatedAt'        => $now,
        ]]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Forwarded to DRC successfully.',
        'data'    => ['status' => 'drc_rc_forwarded', 'currentStage' => 'drc'],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>