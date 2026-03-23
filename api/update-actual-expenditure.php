<?php
/**
 * update-actual-expenditure.php  v3
 *
 * ROOT CAUSE FIX (v2 bug):
 *   v2 re-aggregated amountBookedByPI using  '$sum' => '$amount'
 *   but create-budget-requests.php stores the booked amount as 'requestedAmount'
 *   (not 'amount'), so every time DA filled an actual expenditure, the
 *   re-aggregation summed nulls and reset amountBookedByPI to 0.
 *
 *   v3 uses '$requestedAmount' as the canonical booked field.
 *   It also falls back to '$amount' as a secondary field for legacy docs.
 *
 * Remaining formula (computed at read-time by get-pi-projects.php):
 *   Remaining = Released - Booked + (Booked - Actual) = Released - Actual
 *   (unused booking is returned to the available pool)
 *
 * Accepts:
 *   { requestId,  actualExpenditure }  ← per-request (DA dashboard, preferred)
 *   { projectId,  actualExpenditure }  ← legacy project-level
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';

$input             = json_decode(file_get_contents('php://input'), true);
$requestId         = trim($input['requestId']   ?? '');
$projectId         = trim($input['projectId']   ?? '');
$actualExpenditure = $input['actualExpenditure'] ?? null;

if ($actualExpenditure === null || $actualExpenditure === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'actualExpenditure is required']);
    exit();
}
$actualExpenditure = floatval($actualExpenditure);
if ($actualExpenditure < 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'actualExpenditure cannot be negative']);
    exit();
}

try {
    $db  = getMongoDBConnection();
    $now = new MongoDB\BSON\UTCDateTime();

    // ─────────────────────────────────────────────────────────────────────
    // Helper: re-aggregate booked (requestedAmount) and actual from
    // budget_requests, then sync both fields back onto the project document.
    // Tries string projectId first, then ObjectId, to handle both storage formats.
    // ─────────────────────────────────────────────────────────────────────
    function syncProjectFinancials($db, $projectId, $now): array {
        $tryIds = [$projectId];
        try { $tryIds[] = new MongoDB\BSON\ObjectId($projectId); } catch (Exception $e) {}

        $bookedTotal = 0;
        $actualTotal = 0;

        foreach ($tryIds as $pid) {
            // Sum requestedAmount (canonical) with fallback to amount (legacy)
            // We use $ifNull so legacy docs that stored 'amount' are included.
            $bookedAgg = iterator_to_array($db->budget_requests->aggregate([
                ['$match' => ['projectId' => $pid, 'status' => ['$nin' => ['rejected']]]],
                ['$group' => [
                    '_id'   => null,
                    'total' => ['$sum' => [
                        '$ifNull' => ['$requestedAmount', ['$ifNull' => ['$amount', 0]]]
                    ]],
                ]],
            ]));
            if (!empty($bookedAgg)) {
                $bookedTotal = floatval($bookedAgg[0]['total']);
            }

            $actualAgg = iterator_to_array($db->budget_requests->aggregate([
                ['$match' => [
                    'projectId'        => $pid,
                    'status'           => 'approved',
                    'actualExpenditure' => ['$gt' => 0],
                ]],
                ['$group' => ['_id' => null, 'total' => ['$sum' => '$actualExpenditure']]],
            ]));
            if (!empty($actualAgg)) {
                $actualTotal = floatval($actualAgg[0]['total']);
            }

            if ($bookedTotal > 0 || $actualTotal > 0) break; // found data, no need to retry with ObjectId
        }

        // Sync both fields — booked never resets to 0 now
        $db->projects->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($projectId)],
            ['$set' => [
                'amountBookedByPI'  => $bookedTotal,  // uses requestedAmount, not amount
                'actualExpenditure' => $actualTotal,
                'updatedAt'         => $now,
            ]]
        );

        return ['booked' => $bookedTotal, 'actual' => $actualTotal];
    }

    /* ── PATH A: per-request update (DA dashboard) ──────────────────────── */
    if ($requestId !== '') {

        $br = $db->budget_requests->findOne(['_id' => new MongoDB\BSON\ObjectId($requestId)]);
        if (!$br) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Budget request not found']);
            exit();
        }

        if (($br['status'] ?? '') !== 'approved') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Actual expenditure can only be entered for fully approved requests',
            ]);
            exit();
        }

        // Resolve projectId
        if ($projectId === '') $projectId = (string)($br['projectId'] ?? '');
        if ($projectId === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Could not determine projectId']);
            exit();
        }

        // Validate: actual must not exceed booked for this request
        // Use requestedAmount as canonical; fall back to amount for legacy docs
        $bookedForReq = floatval(
            $br['requestedAmount'] ?? $br['amount'] ?? 0
        );
        if ($bookedForReq > 0 && $actualExpenditure > $bookedForReq) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Actual expenditure (₹' . number_format($actualExpenditure, 2) .
                             ') cannot exceed booked amount (₹' . number_format($bookedForReq, 2) . ')',
            ]);
            exit();
        }

        // Write actualExpenditure onto the budget_request document
        $db->budget_requests->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($requestId)],
            ['$set' => [
                'actualExpenditure'          => $actualExpenditure,
                'actualExpenditureEnteredBy' => 'DA Officer',
                'actualExpenditureEnteredAt' => $now,
                'updatedAt'                  => $now,
            ]]
        );

        // Re-sync project totals (booked stays correct now)
        $totals = syncProjectFinancials($db, $projectId, $now);

        echo json_encode([
            'success' => true,
            'message' => 'Actual expenditure saved. Project totals re-synced.',
            'data'    => [
                'requestId'          => $requestId,
                'projectId'          => $projectId,
                'requestActual'      => $actualExpenditure,
                'projectTotalBooked' => $totals['booked'],
                'projectTotalActual' => $totals['actual'],
                // Remaining = Released - Booked + (Booked - Actual)
                // (computed at read-time from get-pi-projects.php; included here for reference)
            ],
        ]);
        exit();
    }

    /* ── PATH B: legacy project-level update ────────────────────────────── */
    if ($projectId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Either requestId or projectId is required']);
        exit();
    }

    $result = $db->projects->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($projectId)],
        ['$set' => ['actualExpenditure' => $actualExpenditure, 'updatedAt' => $now]]
    );
    if ($result->getMatchedCount() === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Project not found']);
        exit();
    }

    // Re-sync booked too so the legacy path also stays consistent
    syncProjectFinancials($db, $projectId, $now);

    echo json_encode([
        'success' => true,
        'message' => 'Actual expenditure updated (project level)',
        'data'    => ['projectId' => $projectId, 'actualExpenditure' => $actualExpenditure],
    ]);

} catch (Exception $e) {
    error_log('update-actual-expenditure v3: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>