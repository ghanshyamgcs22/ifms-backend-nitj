<?php
// api/da-approve.php
// DA processes a request and forwards it to AR.
// Accepts both fresh 'pending' requests AND 'sent_back_to_da' requests from AR.

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
$requestId  = $input['requestId']  ?? '';
$remarks    = trim($input['remarks'] ?? '');
$approvedBy = $input['approvedBy'] ?? 'DA Officer';

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
    if ($req['currentStage'] !== 'da') {
        echo json_encode(['success' => false, 'message' => 'Request is not at DA stage']); exit();
    }

    // Accept both fresh requests AND requests sent back from AR for re-processing
    $allowedStatuses = ['pending', 'sent_back_to_da'];
    if (!in_array($req['status'], $allowedStatuses)) {
        echo json_encode(['success' => false, 'message' => "Cannot process request with status: {$req['status']}"]); exit();
    }

    $now     = new \MongoDB\BSON\UTCDateTime();
    $history = isset($req['approvalHistory']) ? iterator_to_array($req['approvalHistory']) : [];
    $history[] = [
        'stage'     => 'da',
        'action'    => 'approved',
        'by'        => $approvedBy,
        'timestamp' => date('c'),
        'remarks'   => $remarks,
    ];

    $db->budget_requests->updateOne(
        ['_id' => new \MongoDB\BSON\ObjectId($requestId)],
        ['$set' => [
            'status'          => 'da_approved',
            'currentStage'    => 'ar',
            'daRemarks'       => $remarks,
            'daApprovedAt'    => $now,
            'approvalHistory' => $history,
            'updatedAt'       => $now,
        ]]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Request processed by DA and forwarded to AR.',
        'data'    => ['status' => 'da_approved', 'currentStage' => 'ar'],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>