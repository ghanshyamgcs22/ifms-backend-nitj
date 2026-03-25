<?php
// api/dr-approve.php
// DR gives FINAL approval ONLY IF: amount <= 25000 AND headType = 'consumable'
// Otherwise, always forwards to DRC Office (even if <= 25k but non-consumable).
// Accepts both 'ar_approved' AND 'sent_back_to_dr' (from DRC Office) for re-processing.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit();
}

$input      = json_decode(file_get_contents('php://input'), true);
$requestId  = $input['requestId']  ?? '';
$remarks    = trim($input['remarks'] ?? '');
$approvedBy = $input['approvedBy'] ?? 'DR';

if (!$requestId) {
    echo json_encode(['success' => false, 'message' => 'requestId required']); exit();
}

$DR_THRESHOLD = 25000;

try {
    $db  = getMongoDBConnection();
    $req = $db->budget_requests->findOne(
        ['_id' => new MongoDB\BSON\ObjectId($requestId)],
        ['projection' => ['quotation' => 0]]
    );

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Request not found']); exit();
    }
    if ($req['currentStage'] !== 'dr') {
        echo json_encode(['success' => false, 'message' => 'Request is not at DR stage']); exit();
    }

    // Accept both AR-approved AND requests sent back from DRC Office for re-processing
    $allowedStatuses = ['ar_approved', 'sent_back_to_dr'];
    if (!in_array($req['status'], $allowedStatuses)) {
        echo json_encode(['success' => false, 'message' => "Cannot process request with status: {$req['status']}"]); exit();
    }

    $now    = new MongoDB\BSON\UTCDateTime();
    $amount = 0.0;
    foreach (['requestedAmount', 'amount', 'bookedAmount'] as $f) {
        if (isset($req[$f])) {
            $v = (float)(string)$req[$f];
            if ($v > 0) { $amount = $v; break; }
        }
    }

    $headType = strtolower(trim((string)($req['headType'] ?? '')));
    $history  = isset($req['approvalHistory']) ? iterator_to_array($req['approvalHistory']) : [];

    // ── NEW RULE ──────────────────────────────────────────────────────────────
    // DR is final stage ONLY when: amount <= 25000 AND headType == 'consumable'
    // All other cases (>25k, OR non-consumable even if <=25k) → forward to DRC Office
    $isDRFinal = ($amount <= $DR_THRESHOLD) && ($headType === 'consumable');

    if ($isDRFinal) {
        // ── FINAL APPROVAL by DR ──────────────────────────────────────────────
        $history[] = [
            'stage'     => 'dr',
            'action'    => 'approved',
            'by'        => $approvedBy,
            'timestamp' => date('c'),
            'remarks'   => $remarks,
        ];
        $db->budget_requests->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($requestId)],
            ['$set' => [
                'status'          => 'approved',
                'currentStage'    => 'dr',
                'drRemarks'       => $remarks,
                'drApprovedAt'    => $now,
                'approvedAt'      => $now,
                'approvalHistory' => $history,
                'updatedAt'       => $now,
            ]]
        );
        echo json_encode([
            'success' => true,
            'message' => 'Request finally approved by DR (Consumable ≤ Rs.25,000).',
            'data'    => ['status' => 'approved', 'currentStage' => 'dr'],
        ]);
    } else {
        // ── FORWARD TO DRC OFFICE ─────────────────────────────────────────────
        // Reason: either amount > 25k, OR headType is not consumable (or both)
        $history[] = [
            'stage'     => 'dr',
            'action'    => 'forwarded',
            'by'        => $approvedBy,
            'timestamp' => date('c'),
            'remarks'   => $remarks,
        ];
        $db->budget_requests->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($requestId)],
            ['$set' => [
                'status'          => 'dr_approved',
                'currentStage'    => 'drc_office',
                'drRemarks'       => $remarks,
                'drApprovedAt'    => $now,
                'approvalHistory' => $history,
                'updatedAt'       => $now,
            ]]
        );
        echo json_encode([
            'success' => true,
            'message' => 'Request forwarded by DR to DRC Office.',
            'data'    => ['status' => 'dr_approved', 'currentStage' => 'drc_office'],
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>