<?php
// api/drc-office-forward.php
// DRC Office → forward to DRC (R&C)
// stage: drc_office → drc_rc  |  status: dr_approved → drc_office_forwarded

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
$by        = $input['actionBy']  ?? 'DRC Office';

if (!$requestId) {
    echo json_encode(['success' => false, 'message' => 'requestId required']); exit();
}

try {
    $db  = getMongoDBConnection();
    $req = $db->budget_requests->findOne(['_id' => new MongoDB\BSON\ObjectId($requestId)]);

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Request not found']); exit();
    }
    if ($req['currentStage'] !== 'drc_office' || $req['status'] !== 'dr_approved') {
        echo json_encode(['success' => false, 'message' => 'Request is not at DRC Office stage']); exit();
    }

    $now     = new MongoDB\BSON\UTCDateTime();
    $history = isset($req['approvalHistory']) ? iterator_to_array($req['approvalHistory']) : [];
    $history[] = [
        'stage'     => 'drc_office',
        'action'    => 'forwarded',
        'by'        => $by,
        'timestamp' => date('c'),
        'remarks'   => $remarks,
    ];

    $db->budget_requests->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($requestId)],
        ['$set' => [
            'status'               => 'drc_office_forwarded',
            'currentStage'         => 'drc_rc',
            'drcOfficeRemarks'     => $remarks,
            'drcOfficeForwardedAt' => $now,
            'approvalHistory'      => $history,
            'updatedAt'            => $now,
        ]]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Forwarded to DRC (R&C) successfully.',
        'data'    => ['status' => 'drc_office_forwarded', 'currentStage' => 'drc_rc'],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>