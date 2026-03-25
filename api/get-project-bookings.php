<?php
/**
 * get-project-bookings.php  v4
 *
 * Groups approved bookings by fund release installment.
 *
 * RULES:
 *  - Each request is assigned to the release that was most recent before it
 *  - effectiveAmount = actualExpenditure (if DA filled) else requestedAmount
 *  - Booked per release is capped at that release's totalReleased
 *  - Grand total booked NEVER exceeds totalReleasedAmount
 *
 * GET /api/get-project-bookings.php?projectId=<id>
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';

$projectId = trim($_GET['projectId'] ?? '');
if (empty($projectId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'projectId is required']);
    exit();
}

try {
    $db = getMongoDBConnection();

    $project = $db->projects->findOne(['_id' => new MongoDB\BSON\ObjectId($projectId)]);
    if (!$project) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Project not found']);
        exit();
    }

    $released   = floatval($project['totalReleasedAmount']   ?? 0);
    $sanctioned = floatval($project['totalSanctionedAmount'] ?? 0);

    // ── Fetch fund_releases ordered oldest first ───────────────────────────────
    $fundReleasesCursor = $db->fund_releases->find(
        ['projectId' => $projectId],
        ['sort' => ['releasedAt' => 1]]
    );
    $fundReleases = iterator_to_array($fundReleasesCursor);

    // ── Fetch all approved requests oldest first ───────────────────────────────
    $requestsCursor = $db->budget_requests->find([
        'projectId' => $projectId,
        'status'    => 'approved',
    ], [
        'sort'       => ['createdAt' => 1],
        'projection' => ['quotation' => 0] // CRITICAL: Exclude large binary data from listing
    ]);
    $allRequests = iterator_to_array($requestsCursor);

    // ── Build release timeline ─────────────────────────────────────────────────
    $releaseTimeline = [];
    foreach ($fundReleases as $fr) {
        $raw = $fr['releasedAt'] ?? $fr['createdAt'] ?? null;
        $ts  = ($raw instanceof MongoDB\BSON\UTCDateTime) ? $raw->toDateTime()->getTimestamp() : ($raw ? strtotime((string)$raw) : null);

        $ld  = $fr['letterDate'] ?? null;
        $releaseTimeline[] = [
            'releaseId'     => (string)$fr['_id'],
            'releaseNumber' => $fr['releaseNumber'] ?? '',
            'letterNumber'  => $fr['letterNumber']  ?? '',
            'letterDate'    => ($ld instanceof MongoDB\BSON\UTCDateTime) ? $ld->toDateTime()->format('Y-m-d') : ($ld ?? ''),
            'timestamp'     => $ts,
            'totalReleased' => floatval($fr['totalReleased'] ?? $fr['totalReleaseAmount'] ?? 0),
        ];
    }

    // ── Init release groups ────────────────────────────────────────────────────
    $groups = [];
    foreach ($releaseTimeline as $rt) {
        $groups[$rt['releaseId']] = array_merge($rt, [
            'heads'          => [],
            'totalEffective' => 0,
            'totalActual'    => 0,
            'totalRawBooked' => 0,
        ]);
    }
    $groups['__none__'] = [
        'releaseId' => '__none__', 'releaseNumber' => 'Pre-Release',
        'letterNumber' => '', 'letterDate' => '', 'timestamp' => 0,
        'totalReleased' => 0, 'heads' => [],
        'totalEffective' => 0, 'totalActual' => 0, 'totalRawBooked' => 0,
    ];

    // ── Assign each request to a release ──────────────────────────────────────
    foreach ($allRequests as $req) {
        $raw   = $req['createdAt'] ?? null;
        $reqTs = ($raw instanceof MongoDB\BSON\UTCDateTime) ? $raw->toDateTime()->getTimestamp() : ($raw ? strtotime((string)$raw) : time());

        $assignedId = '__none__';
        $bestTs     = -1;
        foreach ($releaseTimeline as $rt) {
            if ($rt['timestamp'] !== null && $rt['timestamp'] <= $reqTs && $rt['timestamp'] > $bestTs) {
                $bestTs     = $rt['timestamp'];
                $assignedId = $rt['releaseId'];
            }
        }

        $headId   = (string)($req['headId']   ?? 'unknown');
        $headName = $req['headName'] ?? 'Unknown Head';
        $headType = $req['headType'] ?? '';

        $bookedAmt    = floatval($req['requestedAmount'] ?? $req['amount'] ?? 0);
        $actualAmt    = floatval($req['actualExpenditure'] ?? 0);
        $isFilled     = $actualAmt > 0;
        $effectiveAmt = $isFilled ? $actualAmt : $bookedAmt;

        if (!isset($groups[$assignedId]['heads'][$headId])) {
            $groups[$assignedId]['heads'][$headId] = [
                'headId' => $headId, 'headName' => $headName, 'headType' => $headType,
                'bookedAmount' => 0, 'rawBookedAmount' => 0, 'actualExpenditure' => 0,
                'requests' => [],
            ];
        }

        $groups[$assignedId]['heads'][$headId]['bookedAmount']      += $effectiveAmt;
        $groups[$assignedId]['heads'][$headId]['rawBookedAmount']   += $bookedAmt;
        $groups[$assignedId]['heads'][$headId]['actualExpenditure'] += $actualAmt;
        $groups[$assignedId]['heads'][$headId]['requests'][]        = [
            'requestId'         => (string)$req['_id'],
            'requestNumber'     => $req['requestNumber'] ?? '',
            'purpose'           => $req['purpose']       ?? '',
            'invoiceNumber'     => $req['invoiceNumber'] ?? '',
            'bookedAmount'      => $bookedAmt,
            'actualAmount'      => $actualAmt,
            'effectiveAmount'   => $effectiveAmt,
            'isSettled'         => $isFilled,
            'expenditureFilled' => $isFilled,
            'createdAt'         => ($raw instanceof MongoDB\BSON\UTCDateTime) ? $raw->toDateTime()->format('Y-m-d') : null,
        ];

        $groups[$assignedId]['totalEffective'] += $effectiveAmt;
        $groups[$assignedId]['totalActual']    += $actualAmt;
        $groups[$assignedId]['totalRawBooked'] += $bookedAmt;
    }

    // ── Format output ──────────────────────────────────────────────────────────
    $formattedReleases   = [];
    $grandTotalEffective = 0;
    $grandTotalActual    = 0;

    foreach ($groups as $grp) {
        if ($grp['releaseId'] === '__none__' && empty($grp['heads'])) continue;

        // Add running total per request inside each head
        $headsFormatted = [];
        foreach ($grp['heads'] as $head) {
            $cum = 0;
            $requests = array_map(function ($req) use (&$cum) {
                $cum += $req['effectiveAmount'];
                return array_merge($req, ['runningTotal' => $cum]);
            }, $head['requests']);
            $headsFormatted[] = array_merge($head, ['requests' => $requests]);
        }

        // Cap booked at this release's released amount (can never exceed it)
        $cappedEffective = $grp['totalReleased'] > 0
            ? min($grp['totalEffective'], $grp['totalReleased'])
            : $grp['totalEffective'];

        $grandTotalEffective += $cappedEffective;
        $grandTotalActual    += $grp['totalActual'];

        $formattedReleases[] = [
            'releaseId'          => $grp['releaseId'],
            'releaseNumber'      => $grp['releaseNumber'],
            'letterNumber'       => $grp['letterNumber'],
            'letterDate'         => $grp['letterDate'],
            'totalReleased'      => $grp['totalReleased'],
            'totalBooked'        => $cappedEffective,
            'totalRawBooked'     => $grp['totalRawBooked'],
            'totalActual'        => $grp['totalActual'],
            'remainingInRelease' => max(0, $grp['totalReleased'] - $cappedEffective),
            'heads'              => $headsFormatted,
        ];
    }

    $grandAvailable = max(0, $released - $grandTotalEffective);

    // Sync back to project
    $db->projects->updateOne(
        ['_id' => $project['_id']],
        ['$set' => [
            'amountBookedByPI'  => $grandTotalEffective,
            'actualExpenditure' => $grandTotalActual,
            'updatedAt'         => new MongoDB\BSON\UTCDateTime(),
        ]]
    );

    // Build flat heads array (backward compat)
    $flatHeads = [];
    foreach ($formattedReleases as $rel) {
        foreach ($rel['heads'] as $h) {
            $id = $h['headId'];
            if (!isset($flatHeads[$id])) {
                $flatHeads[$id] = $h;
            } else {
                $flatHeads[$id]['bookedAmount']      += $h['bookedAmount'];
                $flatHeads[$id]['actualExpenditure'] += $h['actualExpenditure'];
                $flatHeads[$id]['requests']           = array_merge($flatHeads[$id]['requests'], $h['requests']);
            }
        }
    }

    echo json_encode([
        'success' => true,
        'data'    => [
            'id'                    => $projectId,
            'gpNumber'              => $project['gpNumber']    ?? '',
            'projectName'           => $project['projectName'] ?? '',
            'department'            => $project['department']  ?? '',
            'totalSanctionedAmount' => $sanctioned,
            'totalReleasedAmount'   => $released,
            'amountBookedByPI'      => $grandTotalEffective,
            'actualExpenditure'     => $grandTotalActual,
            'availableBalance'      => $grandAvailable,
            'releases'              => $formattedReleases,
            'heads'                 => array_values($flatHeads),
        ],
    ]);

} catch (Exception $e) {
    error_log("get-project-bookings v4: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>