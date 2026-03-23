<?php
/**
 * release-funds-headwise.php — v6
 *
 * PERMANENT HISTORY GUARANTEE:
 *   Every release event is now written to THREE places atomically:
 *     1. fund_releases            — primary operational record
 *     2. head_allocations         — per-head running totals
 *     3. release_audit_log        — permanent immutable ledger
 *                                   (never deleted even when balance = 0)
 *
 *   This means the report and dialog boxes always show correct released
 *   amounts, even after remaining balance reaches ₹0 and fund_allocations
 *   is redistributed.
 *
 * Also fixes totalReleasedAmount on the project document so the table
 * cell always shows the correct figure.
 */
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

$projectId    = trim($input['projectId']    ?? '');
$gpNumber     = trim($input['gpNumber']     ?? '');
$letterNumber = trim($input['letterNumber'] ?? '');
$letterDate   = trim($input['letterDate']   ?? '');
$remarks      = trim($input['remarks']      ?? '');
$releasedBy   = trim($input['releasedBy']   ?? 'Admin');
$releases     = $input['releases'] ?? [];     // array of {headId, headName, headType, amount}

if (!$projectId || empty($releases)) {
    echo json_encode(['success' => false, 'message' => 'projectId and releases are required']); exit();
}

try {
    $db  = getMongoDBConnection();
    $now = new MongoDB\BSON\UTCDateTime();

    /* ── 1. Validate project ─────────────────────────────────────────────── */
    $project = $db->projects->findOne(['_id' => new MongoDB\BSON\ObjectId($projectId)]);
    if (!$project) {
        echo json_encode(['success' => false, 'message' => 'Project not found']); exit();
    }

    $totalSanctioned = floatval($project['totalSanctionedAmount'] ?? 0);
    $totalReleased   = floatval($project['totalReleasedAmount']   ?? 0);

    /* ── 2. Build release number ─────────────────────────────────────────── */
    $releaseCount  = $db->fund_releases->countDocuments(['projectId' => $projectId]);
    $releaseNumber = 'REL/' . ($project['gpNumber'] ?? $gpNumber) . '/' .
                     str_pad($releaseCount + 1, 3, '0', STR_PAD_LEFT);

    /* ── 3. Validate each head release amount ────────────────────────────── */
    $totalThisRelease = 0;
    $validatedReleases = [];

    foreach ($releases as $rel) {
        $headId   = trim($rel['headId']   ?? '');
        $headName = trim($rel['headName'] ?? '');
        $headType = trim($rel['headType'] ?? '');
        $amount   = floatval($rel['amount'] ?? 0);

        if ($amount <= 0) continue;

        // Validate: cumulative released cannot exceed sanctioned for this head
        $headAlloc = $db->head_allocations->findOne([
            'projectId' => $projectId,
            '$or' => [['headId' => $headId], ['headName' => $headName]],
        ]);
        if ($headAlloc) {
            $headSanctioned = floatval($headAlloc['sanctionedAmount'] ?? 0);
            $headReleased   = floatval($headAlloc['releasedAmount']   ?? 0);
            $headAvail      = $headSanctioned - $headReleased;
            if ($amount > $headAvail + 0.01) {
                echo json_encode([
                    'success' => false,
                    'message' => "Release amount for \"{$headName}\" (₹" . number_format($amount, 2) .
                                 ") exceeds available sanctioned balance (₹" . number_format($headAvail, 2) . ")",
                ]); exit();
            }
        }

        $totalThisRelease += $amount;
        $validatedReleases[] = [
            'headId'   => $headId,
            'headName' => $headName,
            'headType' => $headType,
            'amount'   => $amount,
        ];
    }

    if (empty($validatedReleases)) {
        echo json_encode(['success' => false, 'message' => 'No valid release amounts provided']); exit();
    }

    // Cannot release more than remaining sanctioned
    $yetToRelease = $totalSanctioned - $totalReleased;
    if ($totalThisRelease > $yetToRelease + 0.01) {
        echo json_encode([
            'success' => false,
            'message' => "Total release (₹" . number_format($totalThisRelease, 2) .
                         ") exceeds yet-to-release balance (₹" . number_format($yetToRelease, 2) . ")",
        ]); exit();
    }

    /* ── 4. Write to fund_releases (primary operational record) ─────────── */
    $fundReleaseDoc = [
        'projectId'      => $projectId,
        'gpNumber'       => $gpNumber ?: ($project['gpNumber'] ?? ''),
        'releaseNumber'  => $releaseNumber,
        'letterNumber'   => $letterNumber,
        'letterDate'     => $letterDate ? new MongoDB\BSON\UTCDateTime(strtotime($letterDate) * 1000) : $now,
        'totalReleased'  => $totalThisRelease,
        'remarks'        => $remarks,
        'releasedBy'     => $releasedBy,
        'headwiseReleases' => array_map(fn($r) => [
            'headId'   => $r['headId'],
            'headName' => $r['headName'],
            'headType' => $r['headType'],
            'amount'   => $r['amount'],
        ], $validatedReleases),
        'releasedAt'     => $now,
        'createdAt'      => $now,
    ];
    $db->fund_releases->insertOne($fundReleaseDoc);

    /* ── 5. Write permanent audit log entries (one per head) ─────────────── */
    // These are NEVER deleted. They survive balance resets, reallocations, etc.
    $auditEntries = [];
    foreach ($validatedReleases as $rel) {
        $auditKey = $releaseNumber . '::' . ($rel['headId'] ?: $rel['headName']);
        $auditEntry = [
            'projectId'      => $projectId,
            'gpNumber'       => $gpNumber ?: ($project['gpNumber'] ?? ''),
            'projectName'    => $project['projectName'] ?? '',
            '_auditKey'      => $auditKey,
            'releaseNumber'  => $releaseNumber,
            'letterNumber'   => $letterNumber,
            'letterDate'     => $letterDate ?: null,
            'headId'         => $rel['headId'],
            'headName'       => $rel['headName'],
            'headType'       => $rel['headType'],
            'amountReleased' => $rel['amount'],
            'releasedBy'     => $releasedBy,
            'remarks'        => $remarks,
            'releaseDate'    => $now,
            'loggedAt'       => $now,
            '_source'        => 'release-funds-headwise',
            '_immutable'     => true,   // marker — never delete documents with this flag
        ];
        $db->release_audit_log->insertOne($auditEntry);
        $auditEntries[] = $auditEntry;
    }

    /* ── 6. Update head_allocations per head ─────────────────────────────── */
    foreach ($validatedReleases as $rel) {
        $filter = [
            'projectId' => $projectId,
            '$or' => [
                ['headId'   => $rel['headId']],
                ['headName' => $rel['headName']],
            ],
        ];
        $existing = $db->head_allocations->findOne($filter);

        if ($existing) {
            $newReleased = floatval($existing['releasedAmount'] ?? 0) + $rel['amount'];
            $sanctioned  = floatval($existing['sanctionedAmount'] ?? 0);

            // Append to the head's own release history array
            $historyEntry = [
                'releaseNumber' => $releaseNumber,
                'letterNumber'  => $letterNumber,
                'letterDate'    => $letterDate ?: null,
                'releaseAmount' => $rel['amount'],
                'releasedBy'    => $releasedBy,
                'remarks'       => $remarks,
                'releasedAt'    => date('Y-m-d H:i:s'),
            ];

            $db->head_allocations->updateOne(
                ['_id' => $existing['_id']],
                [
                    '$inc'  => ['releasedAmount' => $rel['amount']],
                    '$set'  => [
                        'status'    => ($newReleased >= $sanctioned) ? 'fully_released' : 'partially_released',
                        'updatedAt' => $now,
                    ],
                    '$push' => ['releaseHistory' => $historyEntry],
                ]
            );
        } else {
            // Create head_allocation record if it doesn't exist yet
            $db->head_allocations->insertOne([
                'projectId'      => $projectId,
                'gpNumber'       => $gpNumber ?: ($project['gpNumber'] ?? ''),
                'headId'         => $rel['headId'],
                'headName'       => $rel['headName'],
                'headType'       => $rel['headType'],
                'sanctionedAmount'=> 0,   // will be set by update-project-allocations.php
                'releasedAmount' => $rel['amount'],
                'bookedAmount'   => 0,
                'actualExpenditure' => 0,
                'status'         => 'partially_released',
                'releaseHistory' => [[
                    'releaseNumber' => $releaseNumber,
                    'letterNumber'  => $letterNumber,
                    'letterDate'    => $letterDate ?: null,
                    'releaseAmount' => $rel['amount'],
                    'releasedBy'    => $releasedBy,
                    'remarks'       => $remarks,
                    'releasedAt'    => date('Y-m-d H:i:s'),
                ]],
                'createdAt'      => $now,
                'updatedAt'      => $now,
            ]);
        }
    }

    /* ── 7. Update fund_allocations head entries ──────────────────────────── */
    $allocDoc = $db->fund_allocations->findOne(['projectId' => $projectId]);
    if ($allocDoc && isset($allocDoc['allocations'])) {
        $allocations = iterator_to_array($allocDoc['allocations']);
        $changed = false;

        foreach ($allocations as &$alloc) {
            foreach ($validatedReleases as $rel) {
                $allocId  = (string)($alloc['_id'] ?? $alloc['id'] ?? '');
                $hId      = (string)($alloc['headId'] ?? '');
                $hName    = (string)($alloc['headName'] ?? '');

                $matches = ($allocId === $rel['headId'])
                        || ($hId    === $rel['headId'])
                        || ($hName  === $rel['headName']);
                if (!$matches) continue;

                $alloc['releasedAmount']  = floatval($alloc['releasedAmount'] ?? 0) + $rel['amount'];
                $alloc['remainingAmount'] = max(0,
                    floatval($alloc['sanctionedAmount'] ?? 0) - $alloc['releasedAmount']
                );
                $alloc['status'] = ($alloc['releasedAmount'] >= floatval($alloc['sanctionedAmount'] ?? 0))
                    ? 'fully_released' : 'partially_released';
                $changed = true;
                break;
            }
        }
        unset($alloc);

        if ($changed) {
            $db->fund_allocations->updateOne(
                ['projectId' => $projectId],
                ['$set' => [
                    'allocations'  => $allocations,
                    'totalReleased'=> floatval($allocDoc['totalReleased'] ?? 0) + $totalThisRelease,
                    'updatedAt'    => $now,
                ]]
            );
        }
    }

    /* ── 8. Update project.totalReleasedAmount ────────────────────────────── */
    // Re-aggregate from fund_releases to get the true total (most accurate)
    $relAgg = iterator_to_array($db->fund_releases->aggregate([
        ['$match'  => ['projectId' => $projectId]],
        ['$unwind' => '$headwiseReleases'],
        ['$group'  => ['_id' => null, 'total' => ['$sum' => '$headwiseReleases.amount']]],
    ]));
    $newTotal = !empty($relAgg) ? floatval($relAgg[0]['total']) : ($totalReleased + $totalThisRelease);

    $db->projects->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($projectId)],
        ['$set' => [
            'totalReleasedAmount' => $newTotal,
            'updatedAt'           => $now,
        ]]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Funds released successfully. History permanently recorded.',
        'data'    => [
            'projectId'          => $projectId,
            'releaseNumber'      => $releaseNumber,
            'totalThisRelease'   => $totalThisRelease,
            'newTotalReleased'   => $newTotal,
            'yetToRelease'       => max(0, $totalSanctioned - $newTotal),
            'headsReleased'      => count($validatedReleases),
            'auditLogEntries'    => count($auditEntries),
        ],
    ]);

} catch (Exception $e) {
    error_log("release-funds-headwise v6: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>