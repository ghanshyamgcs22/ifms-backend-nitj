<?php
// api/drc-office-forward.php
// DRC Office forwards to DR (R&C).
// Accepts both 'dr_approved' AND 'sent_back_to_drc_office' (from DR R&C) for re-processing.

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
$requestId = $input['requestId'] ?? '';
$remarks   = trim($input['remarks'] ?? '');
$actionBy  = $input['actionBy'] ?? 'DRC Office';

if (!$requestId) {
    echo json_encode(['success' => false, 'message' => 'requestId required']); exit();
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
    if ($req['currentStage'] !== 'drc_office') {
        echo json_encode(['success' => false, 'message' => 'Request is not at DRC Office stage']); exit();
    }

    // Accept both DR-approved AND requests sent back from DR R&C for re-forwarding
    $allowedStatuses = ['dr_approved', 'sent_back_to_drc_office'];
    if (!in_array($req['status'], $allowedStatuses)) {
        echo json_encode(['success' => false, 'message' => "Cannot forward request with status: {$req['status']}"]); exit();
    }

    $now     = new \MongoDB\BSON\UTCDateTime();
    $history = isset($req['approvalHistory']) ? iterator_to_array($req['approvalHistory']) : [];
    $history[] = [
        'stage'     => 'drc_office',
        'action'    => 'forwarded',
        'by'        => $actionBy,
        'timestamp' => date('c'),
        'remarks'   => $remarks,
    ];

    $db->budget_requests->updateOne(
        ['_id' => new ObjectId($requestId)],
        ['$set' => [
            'status'             => 'drc_office_forwarded',
            'currentStage'       => 'drc_rc',
            'drcOfficeRemarks'   => $remarks,
            'drcOfficeForwardedAt' => $now,
            'approvalHistory'    => $history,
            'updatedAt'          => $now,
        ]]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Request forwarded to DR (R&C).',
        'data'    => ['status' => 'drc_office_forwarded', 'currentStage' => 'drc_rc'],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>