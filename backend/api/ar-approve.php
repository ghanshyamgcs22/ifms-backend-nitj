<?php
// api/ar-approve.php
// AR recommends a request and forwards it to DR.
// Accepts both 'da_approved' AND 'sent_back_to_ar' (from DR) for re-processing.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// No special imports needed, using absolute namespaces for MongoDB BSON classes

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit();
}

$input      = json_decode(file_get_contents('php://input'), true);
$requestId  = $input['requestId']  ?? '';
$remarks    = trim($input['remarks'] ?? '');
$approvedBy = $input['approvedBy'] ?? 'AR Officer';

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
    if ($req['currentStage'] !== 'ar') {
        echo json_encode(['success' => false, 'message' => 'Request is not at AR stage']); exit();
    }

    // Accept both fresh DA-approved requests AND requests sent back from DR for re-processing
    $allowedStatuses = ['da_approved', 'sent_back_to_ar'];
    if (!in_array($req['status'], $allowedStatuses)) {
        echo json_encode(['success' => false, 'message' => "Cannot recommend request with status: {$req['status']}"]); exit();
    }

    $now     = new \MongoDB\BSON\UTCDateTime();
    $history = isset($req['approvalHistory']) ? iterator_to_array($req['approvalHistory']) : [];
    $history[] = [
        'stage'     => 'ar',
        'action'    => 'approved',
        'by'        => $approvedBy,
        'timestamp' => date('c'),
        'remarks'   => $remarks,
    ];

    $db->budget_requests->updateOne(
        ['_id' => new \MongoDB\BSON\ObjectId($requestId)],
        ['$set' => [
            'status'          => 'ar_approved',
            'currentStage'    => 'dr',
            'arRemarks'       => $remarks,
            'arApprovedAt'    => $now,
            'approvalHistory' => $history,
            'updatedAt'       => $now,
        ]]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Request recommended by AR and forwarded to DR.',
        'data'    => ['status' => 'ar_approved', 'currentStage' => 'dr'],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>