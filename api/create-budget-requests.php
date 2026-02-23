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

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']); exit();
}

// ── Required fields ───────────────────────────────────────────────────────────
$projectId    = $input['projectId']    ?? '';
$gpNumber     = $input['gpNumber']     ?? '';
$projectTitle = $input['projectTitle'] ?? '';
$projectType  = $input['projectType']  ?? '';
$piName       = $input['piName']       ?? '';
$piEmail      = $input['piEmail']      ?? '';
$department   = $input['department']   ?? '';
$headId       = $input['headId']       ?? '';
$headName     = $input['headName']     ?? '';
$headType     = $input['headType']     ?? '';
$amount       = floatval($input['amount'] ?? 0);
$purpose      = trim($input['purpose']      ?? '');
$description  = trim($input['description']  ?? '');
$invoiceNumber= trim($input['invoiceNumber']?? '');

// ── Validation ────────────────────────────────────────────────────────────────
if (!$projectId || !$gpNumber || !$piEmail || !$headId || $amount <= 0 || !$purpose || !$invoiceNumber) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']); exit();
}

try {
    $db  = getMongoDBConnection();
    $now = new MongoDB\BSON\UTCDateTime();

    // ── 1. Fetch project to validate available balance ────────────────────────
    $project = $db->projects->findOne(['_id' => new MongoDB\BSON\ObjectId($projectId)]);
    if (!$project) {
        echo json_encode(['success' => false, 'message' => 'Project not found']); exit();
    }

    $released = floatval($project['totalReleasedAmount'] ?? 0);
    $booked   = floatval($project['amountBookedByPI']    ?? 0);
    $actual   = floatval($project['actualExpenditure']   ?? 0);

    // Available balance formula: Released - Booked + (Booked - Actual)
    $unusedBooking    = max(0, $booked - $actual);
    $availableBalance = max(0, $released - $booked + $unusedBooking);

    if ($amount > $availableBalance) {
        echo json_encode([
            'success' => false,
            'message' => "Requested amount ₹" . number_format($amount, 2) .
                         " exceeds available balance ₹" . number_format($availableBalance, 2),
        ]); exit();
    }

    // ── 2. Also validate head-level available balance ─────────────────────────
    $allocDoc = $db->fund_allocations->findOne(['projectId' => $projectId]);
    if ($allocDoc && isset($allocDoc['allocations'])) {
        foreach ($allocDoc['allocations'] as $alloc) {
            // Match by headId OR by the allocation's own _id/id
            $allocHeadId = $alloc['headId'] ?? '';
            $allocId     = (string)($alloc['_id'] ?? $alloc['id'] ?? '');
            if ($allocHeadId !== $headId && $allocId !== $headId) continue;

            $headReleased = floatval($alloc['releasedAmount']    ?? 0);
            $headBooked   = floatval($alloc['bookedAmount']      ?? 0);
            $headActual   = floatval($alloc['actualExpenditure'] ?? 0);
            $headUnused   = max(0, $headBooked - $headActual);
            $headAvail    = max(0, $headReleased - $headBooked + $headUnused);

            if ($amount > $headAvail) {
                echo json_encode([
                    'success' => false,
                    'message' => "Amount exceeds available balance for head \"{$headName}\". " .
                                 "Available: ₹" . number_format($headAvail, 2),
                ]); exit();
            }
            break;
        }
    }

    // ── 3. Generate request number ────────────────────────────────────────────
    $count = $db->budget_requests->countDocuments([]);
    $requestNumber = 'BR/' . date('Y') . '/' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);

    // ── 4. Insert budget_request ──────────────────────────────────────────────
    $requestDoc = [
        'requestNumber'  => $requestNumber,
        'projectId'      => $projectId,
        'gpNumber'       => $gpNumber,
        'projectTitle'   => htmlspecialchars(strip_tags($projectTitle)),
        'projectType'    => htmlspecialchars(strip_tags($projectType)),
        'piName'         => htmlspecialchars(strip_tags($piName)),
        'piEmail'        => htmlspecialchars(strip_tags($piEmail)),
        'department'     => htmlspecialchars(strip_tags($department)),
        'headId'         => $headId,
        'headName'       => htmlspecialchars(strip_tags($headName)),
        'headType'       => htmlspecialchars(strip_tags($headType)),
        'requestedAmount'=> $amount,
        'purpose'        => htmlspecialchars(strip_tags($purpose)),
        'description'    => htmlspecialchars(strip_tags($description)),
        'invoiceNumber'  => htmlspecialchars(strip_tags($invoiceNumber)),
        'status'         => 'pending',
        'currentStage'   => 'da',
        'daRemarks'      => '',
        'arRemarks'      => '',
        'drRemarks'      => '',
        'approvalHistory'=> [],
        'actualExpenditure' => 0,
        'createdAt'      => $now,
        'updatedAt'      => $now,
    ];

    $result    = $db->budget_requests->insertOne($requestDoc);
    $insertedId = (string) $result->getInsertedId();

    // ── 5. Increment amountBookedByPI on the project ──────────────────────────
    // This reduces the PI's available balance immediately after booking.
    $db->projects->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($projectId)],
        [
            '$inc' => ['amountBookedByPI' => $amount],
            '$set' => ['updatedAt' => $now],
        ]
    );

    // ── 6. Increment bookedAmount on the specific head in fund_allocations ────
    if ($allocDoc) {
        $allocations = isset($allocDoc['allocations']) ? iterator_to_array($allocDoc['allocations']) : [];
        $updated = false;

        foreach ($allocations as &$alloc) {
            $allocHeadId = $alloc['headId'] ?? '';
            $allocId     = (string)($alloc['_id'] ?? $alloc['id'] ?? '');
            if ($allocHeadId !== $headId && $allocId !== $headId) continue;

            $alloc['bookedAmount'] = floatval($alloc['bookedAmount'] ?? 0) + $amount;
            $updated = true;
            break;
        }
        unset($alloc);

        if ($updated) {
            $db->fund_allocations->updateOne(
                ['projectId' => $projectId],
                ['$set' => [
                    'allocations' => $allocations,
                    'updatedAt'   => $now,
                ]]
            );
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Budget request submitted successfully',
        'data'    => [
            'id'            => $insertedId,
            'requestNumber' => $requestNumber,
            'status'        => 'pending',
            'currentStage'  => 'da',
        ],
    ]);

} catch (Exception $e) {
    error_log("create-budget-requests error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>