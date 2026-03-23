<?php
// api/dr-approve.php — FIXED v3
//
// ROOT CAUSE of ₹50,000 showing as "Approved" instead of "Forwarded":
// create-budget-requests.php stores field as 'requestedAmount'
// But BSON reading needs explicit cast. We now try ALL possible field names
// and cast robustly.
//
// ≤ ₹25,000 → final approve  (status=approved,    stage=completed)
// > ₹25,000 → forward to DRC (status=dr_approved, stage=drc_office)

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
$by        = $input['approvedBy'] ?? 'DR';

if (!$requestId) {
    echo json_encode(['success' => false, 'message' => 'requestId required']); exit();
}

try {
    $db  = getMongoDBConnection();
    $req = $db->budget_requests->findOne(['_id' => new MongoDB\BSON\ObjectId($requestId)]);

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Request not found']); exit();
    }
    if ($req['currentStage'] !== 'dr' || $req['status'] !== 'ar_approved') {
        echo json_encode(['success' => false, 'message' => 'Request is not at DR stage']); exit();
    }

    // ── Robust amount extraction ──────────────────────────────────────────────
    // Try every possible field name. Cast via json_decode(json_encode()) to
    // unwrap BSON types completely — this is the most reliable method.
    $docArray = json_decode(json_encode($req), true);

    $amount = 0.0;
    foreach (['requestedAmount', 'amount', 'bookedAmount', 'requestAmount'] as $field) {
        if (isset($docArray[$field]) && $docArray[$field] > 0) {
            $amount = (float)$docArray[$field];
            break;
        }
    }

    // Log for debugging (check server error log if still wrong)
    error_log("DR Approve — requestId: $requestId, amount: $amount, fields available: " .
              implode(', ', array_keys($docArray)));

    $now     = new MongoDB\BSON\UTCDateTime();
    $history = isset($req['approvalHistory']) ? iterator_to_array($req['approvalHistory']) : [];

    if ($amount > 0 && $amount <= 25000) {
        // ── ≤ ₹25,000 → DR gives final approval ──────────────────────────────
        $history[] = [
            'stage'     => 'dr',
            'action'    => 'approved',
            'by'        => $by,
            'timestamp' => date('c'),
            'remarks'   => $remarks,
        ];

        $db->budget_requests->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($requestId)],
            ['$set' => [
                'status'          => 'approved',
                'currentStage'    => 'completed',
                'drRemarks'       => $remarks,
                'drApprovedAt'    => $now,
                'approvalHistory' => $history,
                'updatedAt'       => $now,
            ]]
        );

        echo json_encode([
            'success' => true,
            'message' => 'Budget request fully approved by DR.',
            'data'    => ['status' => 'approved', 'currentStage' => 'completed', 'amount' => $amount],
        ]);

    } else {
        // ── > ₹25,000 (or amount=0 safety fallback) → forward to DRC Office ──
        // If amount somehow still 0, forward to DRC as a safe default to avoid
        // incorrectly marking high-value requests as approved.
        $history[] = [
            'stage'     => 'dr',
            'action'    => 'forwarded',
            'by'        => $by,
            'timestamp' => date('c'),
            'remarks'   => $remarks ?: "Forwarded to DRC Office (amount ₹{$amount} > ₹25,000)",
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
            'message' => 'Request forwarded to DRC Office.',
            'data'    => ['status' => 'dr_approved', 'currentStage' => 'drc_office', 'amount' => $amount],
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>