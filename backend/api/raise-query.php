<?php
// api/raise-query.php
// Sets status = 'query_raised', stores previousStatus for restore on resolve.
// currentStage stays the same so the request stays with the reviewer.

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
$queryBy   = $input['queryBy']   ?? 'Unknown';
$queryTo   = $input['queryTo']   ?? 'pi';

if (!$requestId) { echo json_encode(['success' => false, 'message' => 'requestId required']); exit(); }
if (empty($remarks)) { echo json_encode(['success' => false, 'message' => 'Query remarks required']); exit(); }

try {
    $db  = getMongoDBConnection();
    $req = $db->budget_requests->findOne(
        ['_id' => new ObjectId($requestId)],
        ['projection' => ['quotation' => 0]]
    );

    if (!$req) { echo json_encode(['success' => false, 'message' => 'Request not found']); exit(); }
    if (in_array($req['status'], ['approved', 'rejected'])) {
        echo json_encode(['success' => false, 'message' => 'Cannot raise query on a closed request']); exit();
    }

    $stageLabels = [
        'da' => 'DA', 'ar' => 'AR', 'dr' => 'DR',
        'drc_office' => 'DRC Office', 'drc_rc' => 'DR (R&C)',
        'drc' => 'DRC', 'director' => 'Director',
    ];
    $stageLabel = $stageLabels[$req['currentStage']] ?? strtoupper($req['currentStage']);

    $now     = new UTCDateTime();
    $history = isset($req['approvalHistory']) ? iterator_to_array($req['approvalHistory']) : [];
    $history[] = [
        'stage'     => $req['currentStage'],
        'action'    => 'query_raised',
        'by'        => $queryBy,
        'queryTo'   => $queryTo,
        'timestamp' => date('c'),
        'remarks'   => $remarks,
    ];

    $db->budget_requests->updateOne(
        ['_id' => new ObjectId($requestId)],
        [
            '$set' => [
                // ✅ KEY FIX: change status so both dashboards can filter on it
                'status'           => 'query_raised',
                'previousStatus'   => $req['status'],     // save to restore on resolve
                'hasOpenQuery'     => true,
                'latestQuery'      => [
                    'query'        => $remarks,
                    'raisedBy'     => $queryBy,
                    'raisedByLabel'=> $stageLabel,
                    'raisedAt'     => date('c'),
                    'raisedStage'  => $req['currentStage'],
                    'resolved'     => false,
                ],
                'approvalHistory'  => $history,
                'updatedAt'        => $now,
            ],
            '$push' => [
                'queries' => [
                    'by'        => $queryBy,
                    'byLabel'   => $stageLabel,
                    'to'        => $queryTo,
                    'query'     => $remarks,
                    'stage'     => $req['currentStage'],
                    'timestamp' => date('c'),
                    'resolved'  => false,
                ],
            ],
        ]
    );

    echo json_encode([
        'success' => true,
        'message' => "Query raised to PI by $stageLabel. PI will respond on their dashboard.",
        'data'    => ['status' => 'query_raised', 'queriedBy' => $queryBy],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>