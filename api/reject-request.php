<?php
/**
 * reject-request.php
 * Any stage (da/ar/dr) can reject.
 * On rejection: REVERSES the amountBookedByPI on project and head bookedAmount.
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
require_once __DIR__ . '/../config/database.php';

$input      = json_decode(file_get_contents('php://input'), true);
$requestId  = $input['requestId']  ?? '';
$stage      = $input['stage']      ?? '';
$remarks    = $input['remarks']    ?? '';
$rejectedBy = $input['rejectedBy'] ?? 'Officer';

if (!$requestId || !$stage || !$remarks) {
    echo json_encode(['success'=>false,'message'=>'requestId, stage, and remarks are all required']); exit();
}

try {
    $db  = getMongoDBConnection();
    $req = $db->budget_requests->findOne(['_id' => new MongoDB\BSON\ObjectId($requestId)]);
    if (!$req) { echo json_encode(['success'=>false,'message'=>'Not found']); exit(); }

    $now = new MongoDB\BSON\UTCDateTime();
    $history = isset($req['approvalHistory']) ? iterator_to_array($req['approvalHistory']) : [];
    $history[] = ['stage'=>$stage,'action'=>'rejected','by'=>$rejectedBy,'timestamp'=>date('c'),'remarks'=>$remarks];

    $remarksField = $stage . 'Remarks'; // daRemarks / arRemarks / drRemarks

    $db->budget_requests->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($requestId)],
        ['$set' => [
            'status'           => 'rejected',
            'currentStage'     => $stage . '_rejected',
            $remarksField      => $remarks,
            'approvalHistory'  => $history,
            'updatedAt'        => $now,
        ]]
    );

    // ── Reverse amountBookedByPI on the project ───────────────────────────────
    $amount    = floatval($req['amount']    ?? 0);
    $projectId = $req['projectId'] ?? null;
    $headId    = $req['headId']    ?? null;

    if ($projectId && $amount > 0) {
        $db->projects->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($projectId)],
            [
                '$inc' => ['amountBookedByPI' => -$amount],
                '$set' => ['updatedAt' => $now]
            ]
        );
    }

    // ── Reverse head bookedAmount ─────────────────────────────────────────────
    if ($projectId && $headId && $amount > 0) {
        $db->head_allocations->updateOne(
            ['projectId' => $projectId, 'headId' => $headId],
            [
                '$inc' => ['bookedAmount' => -$amount],
                '$set' => ['updatedAt' => $now]
            ]
        );
    }

    echo json_encode(['success'=>true,'message'=>"Request rejected at {$stage} stage. Balance restored."]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
?>