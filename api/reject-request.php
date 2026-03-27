<?php
// api/reject-request.php — FIXED
// Universal rejection for ALL stages: da | ar | dr | drc_office | drc_rc | drc | director
// ✅ FIX: reads 'requestedAmount' ?? 'amount' consistently

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit();
}

$input      = json_decode(file_get_contents('php://input'), true);
$requestId  = $input['requestId']  ?? '';
$stage      = $input['stage']      ?? '';
$remarks    = trim($input['remarks']    ?? '');
$rejectedBy = $input['rejectedBy'] ?? 'Unknown';

if (!$requestId || !$stage) {
    echo json_encode(['success' => false, 'message' => 'requestId and stage required']); exit();
}
if (empty($remarks)) {
    echo json_encode(['success' => false, 'message' => 'Remarks are required for rejection']); exit();
}

// Stage → required status before rejection is allowed
$stageStatusMap = [
    'da'         => ['pending'],
    'ar'         => ['da_approved'],
    'dr'         => ['ar_approved'],
    'drc_office' => ['dr_approved'],
    'drc_rc'     => ['drc_office_forwarded', 'sent_back_to_drc_rc'],
    'drc'        => ['drc_rc_forwarded', 'sent_back_to_drc'],
    'director'   => ['drc_forwarded'],
];

// Stage → field to store the rejection remarks in
$remarkField = [
    'da'         => 'daRemarks',
    'ar'         => 'arRemarks',
    'dr'         => 'drRemarks',
    'drc_office' => 'drcOfficeRemarks',
    'drc_rc'     => 'drcRcRemarks',
    'drc'        => 'drcRemarks',
    'director'   => 'directorRemarks',
];

if (!array_key_exists($stage, $stageStatusMap)) {
    echo json_encode(['success' => false, 'message' => "Invalid stage: $stage"]); exit();
}

try {
    $db  = getMongoDBConnection();
    $req = $db->budget_requests->findOne(
        ['_id' => new \MongoDB\BSON\ObjectId($requestId)],
        ['projection' => ['quotation' => 0]]
    );

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Request not found']); exit();
    }
    if ($req['status'] === 'rejected') {
        echo json_encode(['success' => false, 'message' => 'Already rejected']); exit();
    }
    if ($req['status'] === 'approved') {
        echo json_encode(['success' => false, 'message' => 'Cannot reject an approved request']); exit();
    }
    if ($req['currentStage'] !== $stage) {
        echo json_encode(['success' => false, 'message' => "Request is not at $stage stage"]); exit();
    }
    if (!in_array($req['status'], $stageStatusMap[$stage])) {
        echo json_encode(['success' => false, 'message' => "Invalid status '{$req['status']}' for rejection at $stage"]); exit();
    }

    $now     = new \MongoDB\BSON\UTCDateTime();
    $history = isset($req['approvalHistory']) ? iterator_to_array($req['approvalHistory']) : [];
    $history[] = [
        'stage'     => $stage,
        'action'    => 'rejected',
        'by'        => $rejectedBy,
        'timestamp' => date('c'),
        'remarks'   => $remarks,
    ];

    $db->budget_requests->updateOne(
        ['_id' => new \MongoDB\BSON\ObjectId($requestId)],
        ['$set' => [
            'status'                  => 'rejected',
            'currentStage'            => $stage,
            $remarkField[$stage]      => $remarks,
            'rejectedAt'              => $now,
            'rejectedBy'              => $rejectedBy,
            'rejectedAtStage'         => $stage,
            'approvalHistory'         => $history,
            'updatedAt'               => $now,
        ]]
    );

    echo json_encode([
        'success' => true,
        'message' => "Request rejected at $stage stage.",
        'data'    => ['status' => 'rejected', 'currentStage' => $stage],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>