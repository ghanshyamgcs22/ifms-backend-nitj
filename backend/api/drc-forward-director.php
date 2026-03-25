<?php
// api/drc-forward-director.php
// DRC → forward to Director.
// ✅ approvalType is set by DR (R&C) and already stored on the document.
//    DRC does NOT set or send approvalType — it is read directly from the DB.

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

$input     = json_decode(file_get_contents('php://input'), true);
$requestId = trim($input['requestId']   ?? '');
$remarks   = trim($input['remarks']     ?? '');
$by        = trim($input['forwardedBy'] ?? 'DRC');

if (!$requestId) {
    echo json_encode(['success' => false, 'message' => 'requestId required']); exit();
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
        echo json_encode(['success' => false, 'message' => 'Invalid status for DRC forward action']); exit();
    }

    // ✅ Read approvalType from the document (set by DR R&C — DRC cannot change it)
    $approvalType = trim((string)($req['approvalType'] ?? ''));
    $allowedApprovalTypes = ['admin', 'admin_cum_financial'];

    if (!in_array($approvalType, $allowedApprovalTypes)) {
        echo json_encode([
            'success' => false,
            'message' => 'Approval type has not been set by DR (R&C). Please send this request back to DR (R&C) to set the approval type before forwarding.',
        ]); exit();
    }

    $now     = new UTCDateTime();
    $history = isset($req['approvalHistory']) ? iterator_to_array($req['approvalHistory']) : [];
    $history[] = [
        'stage'        => 'drc',
        'action'       => 'forwarded',
        'by'           => $by,
        'timestamp'    => date('c'),
        'remarks'      => $remarks,
        'approvalType' => $approvalType, // record in history for audit trail
    ];

    $db->budget_requests->updateOne(
        ['_id' => new ObjectId($requestId)],
        ['$set' => [
            'status'         => 'drc_forwarded',
            'currentStage'   => 'director',
            'drcRemarks'     => $remarks,
            'drcForwardedAt' => $now,
            // approvalType is NOT updated here — it stays as set by DR (R&C)
            'approvalHistory' => $history,
            'updatedAt'       => $now,
        ]]
    );

    $approvalTypeLabel = $approvalType === 'admin'
        ? 'Admin Approval'
        : 'Admin cum Financial Approval';

    echo json_encode([
        'success' => true,
        'message' => "Forwarded to Director. Approval type: {$approvalTypeLabel} (set by DR R&C).",
        'data'    => [
            'status'       => 'drc_forwarded',
            'currentStage' => 'director',
            'approvalType' => $approvalType,
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>