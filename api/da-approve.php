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

$input      = json_decode(file_get_contents('php://input'), true);
$requestId  = $input['requestId']       ?? '';
$remarks    = $input['remarks']         ?? '';
$approvedBy = $input['approvedBy']      ?? 'DA Officer';

// Optional: DA can enter the actual expenditure at approval time.
// If not provided, we fall back to the requested amount (full booking consumed).
$actualExpenditure = isset($input['actualExpenditure'])
    ? floatval($input['actualExpenditure'])
    : null;

if (!$requestId) {
    echo json_encode(['success' => false, 'message' => 'requestId required']); exit();
}

try {
    $db  = getMongoDBConnection();
    $req = $db->budget_requests->findOne(['_id' => new MongoDB\BSON\ObjectId($requestId)]);

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Request not found']); exit();
    }
    if ($req['currentStage'] !== 'da' || $req['status'] !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'Request is not at DA stage']); exit();
    }

    $requestedAmount = floatval($req['requestedAmount'] ?? 0);
    $projectId       = $req['projectId'] ?? null;

    // If DA provides actual expenditure, use it; otherwise treat full booked amount as spent.
    $actualSpent = ($actualExpenditure !== null) ? $actualExpenditure : $requestedAmount;

    // The amount that was booked by PI for this request (always the requestedAmount).
    // We will remove it from amountBookedByPI and add actualSpent to actualExpenditure.
    // Unused booking = requestedAmount - actualSpent  → automatically freed up for PI.
    $bookedReduction    = $requestedAmount;   // remove entire booking
    $expenditureToAdd   = $actualSpent;       // add only what was actually spent

    $now     = new MongoDB\BSON\UTCDateTime();
    $history = isset($req['approvalHistory']) ? iterator_to_array($req['approvalHistory']) : [];
    $history[] = [
        'stage'              => 'da',
        'action'             => 'approved',
        'by'                 => $approvedBy,
        'timestamp'          => date('c'),
        'remarks'            => $remarks,
        'actualExpenditure'  => $actualSpent,
    ];

    // ── 1. Update the budget_request itself ──────────────────────────────────
    $db->budget_requests->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($requestId)],
        ['$set' => [
            'status'             => 'da_approved',
            'currentStage'       => 'ar',
            'daRemarks'          => $remarks,
            'daApprovedAt'       => $now,
            'actualExpenditure'  => $actualSpent,
            'approvalHistory'    => $history,
            'updatedAt'          => $now,
        ]]
    );

    // ── 2. Update the linked project's financial fields ──────────────────────
    // Logic:
    //   amountBookedByPI   -= requestedAmount   (booking is now settled / cleared)
    //   actualExpenditure  += actualSpent        (record what was truly spent)
    //
    // This means the Remaining Balance for PI in the frontend recalculates as:
    //   Released - actualExpenditure  (since actualExpenditure > 0)
    // Any unused booking (requestedAmount - actualSpent) is automatically
    // returned to the PI's available balance — no extra field needed.
    if ($projectId) {
        $projectFilter = ['_id' => new MongoDB\BSON\ObjectId($projectId)];

        // Fetch current values to prevent going below zero
        $project = $db->projects->findOne($projectFilter);

        if ($project) {
            $currentBooked      = floatval($project['amountBookedByPI']   ?? 0);
            $currentExpenditure = floatval($project['actualExpenditure']   ?? 0);

            // Clamp: booked cannot go below 0
            $newBooked      = max(0, $currentBooked - $bookedReduction);
            $newExpenditure = $currentExpenditure + $expenditureToAdd;

            $db->projects->updateOne(
                $projectFilter,
                ['$set' => [
                    'amountBookedByPI'  => $newBooked,
                    'actualExpenditure' => $newExpenditure,
                    'updatedAt'         => $now,
                ]]
            );
        } else {
            error_log("DA Approve: project not found for projectId={$projectId}");
        }
    } else {
        error_log("DA Approve: no projectId on budget_request id={$requestId}");
    }

    echo json_encode([
        'success' => true,
        'message' => 'Approved by DA. Forwarded to AR.',
        'data'    => [
            'status'            => 'da_approved',
            'currentStage'      => 'ar',
            'actualExpenditure' => $actualSpent,
            'bookedReduction'   => $bookedReduction,
        ]
    ]);

} catch (Exception $e) {
    error_log("DA Approve Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>