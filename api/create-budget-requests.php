<?php
// api/create-budget-requests.php  — v4
//
// BALANCE RULE (consistent with get-pi-projects.php):
//   booked    = SUM(requestedAmount) WHERE status != 'rejected'
//   available = releasedAmount - booked
//
//   • Pending/in-stage requests DEDUCT from available immediately.
//   • Rejected requests are excluded → their amount becomes available again.
//   • Approved requests stay deducted permanently.
//   • No "unusedBooking" add-back — that was a bug causing overcounting.
//
// UPLOAD: multipart/form-data (fast path) or legacy JSON (backward compat).

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit();
}

// ── Parse request (multipart fast path or legacy JSON) ────────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isMultipart = str_contains($contentType, 'multipart/form-data');

if ($isMultipart) {
    $get = fn(string $k, string $default = '') => trim($_POST[$k] ?? $default);
    $projectId             = $get('projectId');
    $gpNumber              = $get('gpNumber');
    $fileNumber            = $get('fileNumber');
    $projectTitle          = $get('projectTitle');
    $projectType           = $get('projectType');
    $piName                = $get('piName');
    $piEmail               = $get('piEmail');
    $department            = $get('department');
    $headId                = $get('headId');
    $headName              = $get('headName');
    $headType              = $get('headType');
    $amount                = floatval($_POST['amount'] ?? 0);
    $purpose               = $get('purpose');
    $description           = $get('description');
    $material              = $get('material');
    $mode                  = $get('mode');
    $invoiceNumber         = $get('invoiceNumber');
    $projectCompletionDate = $get('projectEndDate');

    if (empty($_FILES['quotation']) || $_FILES['quotation']['error'] !== UPLOAD_ERR_OK) {
        $uploadErr = $_FILES['quotation']['error'] ?? 'no file';
        echo json_encode(['success' => false, 'message' => "Quotation PDF upload failed (error: {$uploadErr})"]); exit();
    }
    $uploadedFile = $_FILES['quotation'];
    if ($uploadedFile['type'] !== 'application/pdf') {
        echo json_encode(['success' => false, 'message' => 'Only PDF files are allowed']); exit();
    }
    if ($uploadedFile['size'] > 10 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'File must be less than 10 MB']); exit();
    }
    $quotationBytes = file_get_contents($uploadedFile['tmp_name']);
    $quotation      = 'data:application/pdf;base64,' . base64_encode($quotationBytes);
    $quotationName  = $uploadedFile['name'];

} else {
    // Legacy JSON path
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON']); exit();
    }
    $get = fn(string $k, string $default = '') => trim($input[$k] ?? $default);
    $projectId             = $get('projectId');
    $gpNumber              = $get('gpNumber');
    $fileNumber            = $get('fileNumber');
    $projectTitle          = $get('projectTitle');
    $projectType           = $get('projectType');
    $piName                = $get('piName');
    $piEmail               = $get('piEmail');
    $department            = $get('department');
    $headId                = $get('headId');
    $headName              = $get('headName');
    $headType              = $get('headType');
    $amount                = floatval($input['amount'] ?? 0);
    $purpose               = $get('purpose');
    $description           = $get('description');
    $material              = $get('material');
    $mode                  = $get('mode');
    $invoiceNumber         = $get('invoiceNumber');
    $projectCompletionDate = $get('projectEndDate');
    $quotation             = $input['quotation']     ?? '';
    $quotationName         = $input['quotationName'] ?? '';
}

// ── Basic validation ──────────────────────────────────────────────────
if (!$projectId || !$gpNumber || !$piEmail || !$headId || !$headName) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields (projectId, gpNumber, piEmail, headId, headName)']); exit();
}
if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']); exit();
}
if (!$purpose || !$description || !$material || !$mode || !$invoiceNumber) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields (purpose, description, material, mode, invoiceNumber)']); exit();
}
if (!$quotation) {
    echo json_encode(['success' => false, 'message' => 'Quotation PDF is required']); exit();
}

try {
    $db  = getMongoDBConnection();
    $now = new MongoDB\BSON\UTCDateTime();

    // ── 1. Load project ───────────────────────────────────────────────
    try { $projectObjId = new MongoDB\BSON\ObjectId($projectId); }
    catch (Exception $e) { echo json_encode(['success' => false, 'message' => 'Invalid projectId format']); exit(); }

    $project = $db->projects->findOne(['_id' => $projectObjId]);
    if (!$project) {
        echo json_encode(['success' => false, 'message' => 'Project not found']); exit();
    }
    if (in_array($project['status'] ?? '', ['rejected', 'completed'])) {
        echo json_encode(['success' => false, 'message' => 'Project is not active']); exit();
    }

    $projectReleased = floatval($project['totalReleasedAmount'] ?? 0);
    if ($projectReleased <= 0) {
        echo json_encode(['success' => false, 'message' => 'No funds have been released for this project yet']); exit();
    }

    // ── 2. Live project-level booked (excludes rejected) ─────────────
    // RULE: booked = SUM(requestedAmount) WHERE status != 'rejected'
    // This means pending + in-stage + approved requests all count.
    // Rejected requests are excluded → their amount is freed up again.
    $projectBookingAgg = $db->budget_requests->aggregate([
        ['$match' => [
            'projectId' => $projectId,
            'status'    => ['$ne' => 'rejected'],
        ]],
        ['$group' => ['_id' => null, 'booked' => ['$sum' => '$requestedAmount']]],
    ]);
    $projectBookingRow = iterator_to_array($projectBookingAgg);
    $projectBooked     = floatval($projectBookingRow[0]['booked'] ?? 0);
    $projectAvailable  = max(0.0, $projectReleased - $projectBooked);

    if ($amount > $projectAvailable) {
        echo json_encode([
            'success' => false,
            'message' => sprintf(
                'Amount ₹%.2f exceeds project available balance ₹%.2f '
                . '(Released: ₹%.2f, Currently booked: ₹%.2f). '
                . 'Rejected requests are excluded and can be re-submitted.',
                $amount, $projectAvailable, $projectReleased, $projectBooked
            ),
            'balances' => [
                'projectReleased'  => $projectReleased,
                'projectBooked'    => $projectBooked,
                'projectAvailable' => $projectAvailable,
                'requested'        => $amount,
            ],
        ]); exit();
    }

    // ── 3. Load head allocation (3-level fallback) ────────────────────
    $headAlloc   = null;
    $pidOrFilter = ['$or' => [['projectId' => $projectId], ['projectId' => $projectObjId]]];

    try {
        $headObjId = new MongoDB\BSON\ObjectId($headId);
        $headAlloc = $db->head_allocations->findOne(array_merge(['_id' => $headObjId], $pidOrFilter));
    } catch (Exception $e) {}

    if (!$headAlloc && $headId !== '') {
        $headAlloc = $db->head_allocations->findOne(array_merge(['headId' => $headId], $pidOrFilter));
    }
    if (!$headAlloc && $headName !== '') {
        $headAlloc = $db->head_allocations->findOne(array_merge(
            ['headName' => new MongoDB\BSON\Regex('^' . preg_quote(trim($headName), '/') . '$', 'i')],
            $pidOrFilter
        ));
    }

    if (!$headAlloc) {
        $availableCursor = $db->head_allocations->find($pidOrFilter, ['projection' => ['headId' => 1, 'headName' => 1, '_id' => 1]]);
        $availableNames  = [];
        foreach ($availableCursor as $h) {
            $availableNames[] = ($h['headName'] ?? '(no name)') . ' [_id:' . (string)($h['_id']) . ', headId:' . ($h['headId'] ?? 'n/a') . ']';
        }
        echo json_encode([
            'success' => false,
            'message' => "Budget head '{$headName}' not found for this project",
            '_debug'  => ['searchedProjectId' => $projectId, 'searchedHeadId' => $headId, 'searchedHeadName' => $headName, 'availableHeadsInDB' => $availableNames],
        ]); exit();
    }

    $headReleased = floatval($headAlloc['releasedAmount'] ?? 0);
    if ($headReleased <= 0) {
        echo json_encode(['success' => false, 'message' => "No funds released under head '{$headName}'"]); exit();
    }

    $canonicalHeadId   = (string)($headAlloc['headId']   ?? $headId);
    $canonicalHeadName = (string)($headAlloc['headName'] ?? $headName);

    // ── 4. Live head-level booked (excludes rejected) ─────────────────
    // Same rule as project level — rejected requests freed up, everything else counts.
    $headBookingAgg = $db->budget_requests->aggregate([
        ['$match' => [
            'projectId' => $projectId,
            'status'    => ['$ne' => 'rejected'],
            '$or'       => [
                ['headId'   => $headId],
                ['headId'   => $canonicalHeadId],
                ['headName' => $headName],
                ['headName' => $canonicalHeadName],
            ],
        ]],
        ['$group' => ['_id' => null, 'booked' => ['$sum' => '$requestedAmount']]],
    ]);
    $headBookingRow = iterator_to_array($headBookingAgg);
    $headBooked     = floatval($headBookingRow[0]['booked'] ?? 0);
    $headAvailable  = max(0.0, $headReleased - $headBooked);

    if ($amount > $headAvailable) {
        echo json_encode([
            'success' => false,
            'message' => sprintf(
                'Amount ₹%.2f exceeds head "%s" available balance ₹%.2f '
                . '(Released: ₹%.2f, Currently booked: ₹%.2f). '
                . 'Rejected requests are excluded and can be re-submitted.',
                $amount, $canonicalHeadName, $headAvailable, $headReleased, $headBooked
            ),
            'balances' => [
                'headReleased'  => $headReleased,
                'headBooked'    => $headBooked,
                'headAvailable' => $headAvailable,
                'requested'     => $amount,
            ],
        ]); exit();
    }

    // ── 5. Generate unique request number ─────────────────────────────
    $count         = $db->budget_requests->countDocuments([]);
    $requestNumber = 'BR/' . date('Y') . '/' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);

    // ── 6. Insert request document ────────────────────────────────────
    $requestDoc = [
        'requestNumber'            => $requestNumber,
        'projectId'                => $projectId,
        'gpNumber'                 => $gpNumber,
        'fileNumber'               => $fileNumber,
        'projectTitle'             => $projectTitle,
        'projectType'              => $projectType,
        'piName'                   => $piName,
        'piEmail'                  => $piEmail,
        'department'               => $department,
        'headId'                   => $canonicalHeadId,
        'headName'                 => $canonicalHeadName,
        'headType'                 => $headType ?: (string)($headAlloc['headType'] ?? ''),
        'requestedAmount'          => $amount,
        'actualExpenditure'        => 0,

        // Audit snapshot at submission time
        'snapshotProjectReleased'  => $projectReleased,
        'snapshotProjectBooked'    => $projectBooked,
        'snapshotProjectAvailable' => $projectAvailable,
        'snapshotHeadReleased'     => $headReleased,
        'snapshotHeadBooked'       => $headBooked,
        'snapshotHeadAvailable'    => $headAvailable,

        'purpose'                  => $purpose,
        'description'              => $description,
        'material'                 => $material,
        'mode'                     => $mode,
        'invoiceNumber'            => $invoiceNumber,
        'projectCompletionDate'    => $projectCompletionDate,
        'quotation'                => $quotation,
        'quotationFileName'        => $quotationName,

        'status'                   => 'pending',
        'currentStage'             => 'da',
        'previousStatus'           => '',
        'approvalHistory'          => [],
        'hasOpenQuery'             => false,
        'latestQuery'              => null,

        'daRemarks'                => '',
        'arRemarks'                => '',
        'drRemarks'                => '',
        'drcOfficeRemarks'         => '',
        'drcRcRemarks'             => '',
        'drcRemarks'               => '',
        'directorRemarks'          => '',
        'rejectedBy'               => '',
        'rejectedAtStage'          => '',
        'rejectedAtStageLabel'     => '',
        'rejectionRemarks'         => '',

        'createdAt'                => $now,
        'updatedAt'                => $now,
    ];

    $result = $db->budget_requests->insertOne($requestDoc);
    if (!$result->getInsertedId()) {
        throw new Exception('Failed to insert budget request');
    }

    // ── 7. Sync denormalised booked amounts on project + head ─────────
    // These are the new post-submission booked totals.
    // get-pi-projects.php will recompute from aggregation on next load,
    // but we sync now so other endpoints reading the denormalised field
    // get a reasonably fresh value.
    $newProjectBooked = $projectBooked + $amount;
    $db->projects->updateOne(
        ['_id' => $projectObjId],
        ['$set' => ['amountBookedByPI' => $newProjectBooked, 'updatedAt' => $now]]
    );

    $newHeadBooked = $headBooked + $amount;
    $db->head_allocations->updateOne(
        ['_id' => $headAlloc['_id']],
        ['$set' => ['bookedAmount' => $newHeadBooked, 'updatedAt' => $now]]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Budget request created successfully',
        'data'    => [
            'id'            => (string)$result->getInsertedId(),
            'requestNumber' => $requestNumber,
            // Return updated balances so the frontend can patch its local state instantly
            'updatedBalances' => [
                'projectReleased'  => $projectReleased,
                'projectBooked'    => $newProjectBooked,
                'projectAvailable' => max(0.0, $projectReleased - $newProjectBooked),
                'headReleased'     => $headReleased,
                'headBooked'       => $newHeadBooked,
                'headAvailable'    => max(0.0, $headReleased - $newHeadBooked),
            ],
        ],
    ]);

} catch (Exception $e) {
    error_log('create-budget-requests error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>