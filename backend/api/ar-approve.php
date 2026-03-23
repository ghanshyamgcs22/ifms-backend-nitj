<?php
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
$requestId = $input['requestId']  ?? '';
$remarks   = $input['remarks']    ?? '';
$by        = $input['approvedBy'] ?? 'AR Officer';

if (!$requestId) { echo json_encode(['success' => false, 'message' => 'requestId required']); exit(); }

try {
    $db  = getMongoDBConnection();
    $req = $db->budget_requests->findOne(['_id' => new MongoDB\BSON\ObjectId($requestId)]);

    if (!$req) { echo json_encode(['success' => false, 'message' => 'Request not found']); exit(); }
    if ($req['currentStage'] !== 'ar' || $req['status'] !== 'da_approved') {
        echo json_encode(['success' => false, 'message' => 'Request is not at AR stage']); exit();
    }

    $now     = new MongoDB\BSON\UTCDateTime();
    $history = isset($req['approvalHistory']) ? iterator_to_array($req['approvalHistory']) : [];
    $history[] = [
        'stage'     => 'ar',
        'action'    => 'approved',
        'by'        => $by,
        'timestamp' => date('c'),
        'remarks'   => $remarks,
    ];

    $db->budget_requests->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($requestId)],
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
        'message' => 'AR approved. Forwarded to DR.',
        'data'    => ['status' => 'ar_approved', 'currentStage' => 'dr']
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>