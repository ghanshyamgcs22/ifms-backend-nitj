<?php
// api/drc-special-approve.php
// DRC Special Approval — DRC directly approves without forwarding to Director.
// ✅ approvalType is set by DR (R&C) and read from DB — DRC cannot change it.
// ✅ Sets status = "approved", currentStage stays "drc"
// ✅ specialApproval = true flags this for certificate generation

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit();
}

$input      = json_decode(file_get_contents('php://input'), true);
$requestId  = trim($input['requestId']  ?? '');
$remarks    = trim($input['remarks']    ?? '');
$approvedBy = trim($input['approvedBy'] ?? 'DRC');

if (!$requestId) {
    echo json_encode(['success' => false, 'message' => 'requestId is required']); exit();
}
if (empty($remarks)) {
    echo json_encode(['success' => false, 'message' => 'Remarks are required for special approval.']); exit();
}

try {
    $db  = getMongoDBConnection();
    $req = $db->budget_requests->findOne(
        ['_id' => new ObjectId($requestId)],
        ['projection' => ['quotation' => 0]]
    );

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Request not found']); exit();
    }
    if ($req['currentStage'] !== 'drc') {
        echo json_encode(['success' => false, 'message' => 'Request is not at DRC stage']); exit();
    }

    $allowedStatuses = ['drc_rc_forwarded', 'sent_back_to_drc'];
    if (!in_array($req['status'], $allowedStatuses)) {
        echo json_encode(['success' => false, 'message' => "Cannot approve request with current status: {$req['status']}"]); exit();
    }

    // ✅ Read approvalType from DB — set by DR (R&C), not by DRC
    $approvalType = trim((string)($req['approvalType'] ?? ''));
    $allowedApprovalTypes = ['admin', 'admin_cum_financial'];

    if (!in_array($approvalType, $allowedApprovalTypes)) {
        echo json_encode([
            'success' => false,
            'message' => 'Approval type has not been set by DR (R&C). Please send this request back to DR (R&C) to set the approval type before using Special Approval.',
        ]); exit();
    }

    $now     = new UTCDateTime();
    $history = isset($req['approvalHistory']) ? iterator_to_array($req['approvalHistory']) : [];
    $history[] = [
        'stage'           => 'drc',
        'action'          => 'special_approved',
        'by'              => $approvedBy,
        'timestamp'       => date('c'),
        'remarks'         => $remarks,
        'approvalType'    => $approvalType,
        'specialApproval' => true,
    ];

    $db->budget_requests->updateOne(
        ['_id' => new ObjectId($requestId)],
        ['$set' => [
            'status'          => 'approved',
            'currentStage'    => 'drc',       // stays at drc — never reached director
            'drcRemarks'      => $remarks,
            // approvalType is NOT updated — stays as set by DR (R&C)
            'specialApproval' => true,         // flag for certificate / reporting
            'approvedBy'      => $approvedBy,
            'approvedAt'      => $now,
            'drcApprovedAt'   => $now,
            'approvalHistory' => $history,
            'updatedAt'       => $now,
        ]]
    );

    $approvalTypeLabel = $approvalType === 'admin'
        ? 'Admin Approval'
        : 'Admin cum Financial Approval';

    echo json_encode([
        'success' => true,
        'message' => "Request approved by DRC (Special Approval). Type: {$approvalTypeLabel} (set by DR R&C).",
        'data'    => [
            'status'          => 'approved',
            'currentStage'    => 'drc',
            'approvalType'    => $approvalType,
            'specialApproval' => true,
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>