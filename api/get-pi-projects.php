<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit();
}

$piEmail = $_GET['piEmail'] ?? '';
if (empty($piEmail)) {
    echo json_encode(['success' => false, 'message' => 'piEmail is required']); exit();
}

try {
    $db = getMongoDBConnection();

    // ── Fetch all active projects for this PI ───────────────
    $cursor = $db->projects->find([
        'piEmail' => $piEmail,
        'status'  => ['$nin' => ['rejected', 'completed']],
    ], ['projection' => ['sanctionedLetterFile' => 0]]);
    $projectsRaw = iterator_to_array($cursor);
    $projectIds  = array_map(fn($p) => (string)$p['_id'], $projectsRaw);

    if (empty($projectIds)) {
        echo json_encode(['success' => true, 'data' => [], 'count' => 0]);
        exit();
    }

    // ── 1. Batch Aggregation for Project Totals ──────────────────────────
    // booked = SUM of requestedAmount for ALL non-rejected requests (pending + approved)
    // actual = SUM of actualExpenditure for approved requests only
    $projectBookingPipeline = [
        ['$match' => ['projectId' => ['$in' => $projectIds]]],
        ['$group' => [
            '_id'    => '$projectId',
            'booked' => ['$sum' => [
                '$cond' => [['$ne' => ['$status', 'rejected']], '$requestedAmount', 0]
            ]],
            'actual' => ['$sum' => [
                '$cond' => [['$eq' => ['$status', 'approved']], '$actualExpenditure', 0]
            ]]
        ]]
    ];
    $projectTotalsRaw = iterator_to_array($db->budget_requests->aggregate($projectBookingPipeline));
    $projectTotals    = [];
    foreach ($projectTotalsRaw as $pt) { $projectTotals[$pt['_id']] = $pt; }

    // ── 2. Batch Aggregation for Head Totals ────────────────────────────
    // Same rule: booked excludes rejected, actual is approved only
    $headBookingPipeline = [
        ['$match' => ['projectId' => ['$in' => $projectIds]]],
        ['$group' => [
            '_id' => [
                'projectId' => '$projectId',
                'headId'    => '$headId',
                'headName'  => '$headName'
            ],
            'booked' => ['$sum' => [
                '$cond' => [['$ne' => ['$status', 'rejected']], '$requestedAmount', 0]
            ]],
            'actual' => ['$sum' => [
                '$cond' => [['$eq' => ['$status', 'approved']], '$actualExpenditure', 0]
            ]]
        ]]
    ];
    $headTotalsRaw = iterator_to_array($db->budget_requests->aggregate($headBookingPipeline));
    $headTotals    = [];
    foreach ($headTotalsRaw as $ht) {
        $key = $ht['_id']['projectId'] . '|' . ($ht['_id']['headId'] ?: $ht['_id']['headName']);
        $headTotals[$key] = $ht;
    }

    // ── 3. Fetch Head Allocations in one go ──────────────────────────────
    $headAllocsRaw    = iterator_to_array($db->head_allocations->find(['projectId' => ['$in' => $projectIds]]));
    $allocsByProject  = [];
    foreach ($headAllocsRaw as $ha) { $allocsByProject[(string)$ha['projectId']][] = $ha; }

    // ── 4. Main Process Loop ──────────────────────────────────────────────
    $projects        = [];
    $bulkOpsProject  = [];
    $bulkOpsHeads    = [];

    foreach ($projectsRaw as $project) {
        $projectId = (string)$project['_id'];
        $released  = floatval($project['totalReleasedAmount'] ?? 0);
        $booked    = floatval($projectTotals[$projectId]['booked'] ?? 0);
        $actual    = floatval($projectTotals[$projectId]['actual'] ?? 0);

        // CORRECT FORMULA:
        // available = released - booked
        // "booked" already only counts non-rejected requests (pending + approved).
        // When a request is rejected, its amount is removed from booked → available rises again.
        // When approved, it stays in booked permanently (until actual expenditure is filled).
        // There is NO "unusedBooking" add-back — that was the bug causing overcounting.
        $availableBalance = max(0.0, $released - $booked);

        // Sync denormalised project fields if they drifted
        if (abs($booked - floatval($project['amountBookedByPI'] ?? -1)) > 0.001
            || abs($actual - floatval($project['actualExpenditure'] ?? -1)) > 0.001) {
            $bulkOpsProject[] = [
                'updateOne' => [
                    ['_id' => $project['_id']],
                    ['$set' => [
                        'amountBookedByPI'  => $booked,
                        'actualExpenditure' => $actual,
                        'updatedAt'         => new MongoDB\BSON\UTCDateTime(),
                    ]]
                ]
            ];
        }

        // ── Heads ──────────────────────────────────────────────────────────
        $heads        = [];
        $projectHeads = $allocsByProject[$projectId] ?? [];

        foreach ($projectHeads as $alloc) {
            $headReleased = floatval($alloc['releasedAmount'] ?? 0);
            if ($headReleased <= 0) continue;

            $headId   = (string)($alloc['headId']   ?? '');
            $headName = (string)($alloc['headName'] ?? '');

            $hKey1 = $projectId . '|' . $headId;
            $hKey2 = $projectId . '|' . $headName;
            $ht    = $headTotals[$hKey1] ?? $headTotals[$hKey2] ?? ['booked' => 0, 'actual' => 0];

            $headBooked = floatval($ht['booked']);
            $headActual = floatval($ht['actual']);

            // CORRECT FORMULA (same rule as project level):
            // available = released - booked  (rejected requests excluded from booked)
            $headAvail = max(0.0, $headReleased - $headBooked);

            // Sync head allocation document if drifted
            if (abs($headBooked - floatval($alloc['bookedAmount'] ?? -1)) > 0.001
                || abs($headActual - floatval($alloc['actualExpenditure'] ?? -1)) > 0.001) {
                $bulkOpsHeads[] = [
                    'updateOne' => [
                        ['_id' => $alloc['_id']],
                        ['$set' => [
                            'bookedAmount'      => $headBooked,
                            'actualExpenditure' => $headActual,
                            'updatedAt'         => new MongoDB\BSON\UTCDateTime(),
                        ]]
                    ]
                ];
            }

            $heads[] = [
                'id'                => (string)$alloc['_id'],
                'headId'            => $headId,
                'headName'          => $headName,
                'headType'          => $alloc['headType'] ?? '',
                'sanctionedAmount'  => floatval($alloc['sanctionedAmount'] ?? 0),
                'releasedAmount'    => $headReleased,
                'bookedAmount'      => $headBooked,   // live-computed from budget_requests
                'actualExpenditure' => $headActual,
                'availableBalance'  => $headAvail,    // = releasedAmount - bookedAmount
            ];
        }

        $formatDate = function ($val) {
            if ($val instanceof MongoDB\BSON\UTCDateTime) return $val->toDateTime()->format('Y-m-d');
            return $val ?? null;
        };

        $projects[] = [
            'id'                    => $projectId,
            'gpNumber'              => $project['gpNumber']      ?? '',
            'projectName'           => $project['projectName']   ?? '',
            'modeOfProject'         => $project['modeOfProject'] ?? '',
            'piName'                => $project['piName']        ?? '',
            'piEmail'               => $project['piEmail']       ?? '',
            'department'            => $project['department']    ?? '',
            'projectStartDate'      => $formatDate($project['projectStartDate'] ?? null),
            'projectEndDate'        => $formatDate($project['projectEndDate']   ?? null),
            'totalSanctionedAmount' => floatval($project['totalSanctionedAmount'] ?? 0),
            'totalReleasedAmount'   => $released,
            'amountBookedByPI'      => $booked,            // live-computed
            'actualExpenditure'     => $actual,
            'availableBalance'      => $availableBalance,  // = released - booked
            'status'                => $project['status'] ?? 'active',
            'heads'                 => $heads,
        ];
    }

    // ── 5. Bulk Sync ──────────────────────────────────────────────────────
    if (!empty($bulkOpsProject)) { $db->projects->bulkWrite($bulkOpsProject); }
    if (!empty($bulkOpsHeads))   { $db->head_allocations->bulkWrite($bulkOpsHeads); }

    echo json_encode(['success' => true, 'data' => $projects, 'count' => count($projects)]);

} catch (Exception $e) {
    error_log("get-pi-projects error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>