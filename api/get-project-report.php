<?php
/**
 * get-project-report.php — v6
 *
 * FIXES:
 *  - Release history: reads from fund_releases collection (correct fields)
 *  - Booked = sum of effectiveAmounts (actual if DA filled, else booked)
 *  - Per-release breakdown shown in report
 *  - Actual expenditure per-request with timestamp when DA entered it
 *  - Available balance = Released - Effective Booked (never goes negative)
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
ob_start();

try {
    require_once __DIR__ . '/../config/database.php';
    $db = getMongoDBConnection();
    if (!$db) throw new Exception('Failed to connect to MongoDB');

    $projectId = trim($_GET['projectId'] ?? '');
    if (empty($projectId)) throw new Exception('projectId is required');

    /* ── Date formatters ──────────────────────────────────────────────────── */
    $fmtDT = function ($val) {
        if (!$val) return null;
        try {
            $dt = ($val instanceof MongoDB\BSON\UTCDateTime)
                ? $val->toDateTime()
                : new DateTime((string)$val, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
            return $dt->format('d M Y, h:i A');
        } catch (Exception $e) { return (string)$val; }
    };
    $fmtD = function ($val) use ($fmtDT) {
        $s = $fmtDT($val);
        return $s ? preg_replace('/,.*/', '', $s) : null;
    };
    $fmtPlainDate = function ($v) {
        if (!$v) return null;
        if ($v instanceof MongoDB\BSON\UTCDateTime) {
            $dt = $v->toDateTime(); $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
            return $dt->format('d M Y');
        }
        try { return (new DateTime((string)$v))->format('d M Y'); }
        catch (Exception $e) { return (string)$v; }
    };

    /* ── 1. Load project ──────────────────────────────────────────────────── */
    $project = $db->projects->findOne(['_id' => new MongoDB\BSON\ObjectId($projectId)]);
    if (!$project) throw new Exception('Project not found');

    $sanctioned = floatval($project['totalSanctionedAmount'] ?? 0);
    $released   = floatval($project['totalReleasedAmount']   ?? 0);

    $projectData = [
        'id'                    => (string)$project['_id'],
        'gpNumber'              => $project['gpNumber']          ?? '',
        'projectName'           => $project['projectName']       ?? '',
        'piName'                => $project['piName']            ?? '',
        'piEmail'               => $project['piEmail']           ?? '',
        'department'            => $project['department']        ?? '',
        'modeOfProject'         => $project['modeOfProject']     ?? '',
        'projectAgencyName'     => $project['projectAgencyName'] ?? '',
        'sanctionOrderNo'       => $project['sanctionOrderNo']   ?? '',
        'nameOfScheme'          => $project['nameOfScheme']      ?? '',
        'bankDetails'           => $project['bankDetails']       ?? 'Canara Bank',
        'status'                => $project['status']            ?? 'pending',
        'projectStartDate'      => $fmtD($project['projectStartDate'] ?? null),
        'projectEndDate'        => $fmtD($project['projectEndDate']   ?? null),
        'originalEndDate'       => $fmtD($project['originalEndDate']  ?? null),
        'hasExtension'          => (bool)($project['hasExtension']    ?? false),
        'totalYears'            => floatval($project['totalYears']    ?? 0),
        'totalSanctionedAmount' => $sanctioned,
        'totalReleasedAmount'   => $released,
        'createdAt'             => $fmtDT($project['createdAt'] ?? null),
        'updatedAt'             => $fmtDT($project['updatedAt'] ?? null),
    ];

    /* ── 2. Fund Releases (from fund_releases collection) ─────────────────── */
    $fundReleasesCursor = $db->fund_releases->find(
        ['projectId' => $projectId],
        ['sort' => ['releasedAt' => 1]]
    );
    $fundReleases = iterator_to_array($fundReleasesCursor);

    $releaseTimeline = []; // for assignment logic
    $releaseHistory  = []; // for report display

    foreach ($fundReleases as $fr) {
        $raw = $fr['releasedAt'] ?? $fr['createdAt'] ?? null;
        $ts  = ($raw instanceof MongoDB\BSON\UTCDateTime)
            ? $raw->toDateTime()->getTimestamp()
            : ($raw ? strtotime((string)$raw) : null);

        $ld = $fr['letterDate'] ?? null;
        $totalRel = floatval($fr['totalReleased'] ?? $fr['totalReleaseAmount'] ?? 0);

        // Per-head breakdown inside this release
        $headwiseReleases = [];
        if (isset($fr['headwiseReleases']) && is_array($fr['headwiseReleases'])) {
            foreach ($fr['headwiseReleases'] as $hr) {
                $headwiseReleases[] = [
                    'headId'              => (string)($hr['headId']   ?? ''),
                    'headName'            => $hr['headName']           ?? '',
                    'headType'            => $hr['headType']           ?? '',
                    'sanctionedAmount'    => floatval($hr['sanctionedAmount']    ?? 0),
                    'previouslyReleased'  => floatval($hr['previouslyReleased']  ?? 0),
                    'releaseAmount'       => floatval($hr['releaseAmount']        ?? 0),
                    'newTotalReleased'    => floatval($hr['newTotalReleased']     ?? 0),
                ];
            }
        }

        $releaseEntry = [
            'releaseId'        => (string)$fr['_id'],
            'releaseNumber'    => $fr['releaseNumber'] ?? '',
            'letterNumber'     => $fr['letterNumber']  ?? '',
            'letterDate'       => $fmtPlainDate($ld),
            'releasedAt'       => $fmtDT($raw),
            'timestamp'        => $ts,
            'totalReleased'    => $totalRel,
            'releasedBy'       => $fr['releasedBy'] ?? '',
            'remarks'          => $fr['remarks']    ?? '',
            'headwiseReleases' => $headwiseReleases,
        ];

        $releaseTimeline[] = $releaseEntry;
        $releaseHistory[]  = $releaseEntry;
    }

    /* ── 3. Budget requests — with effectiveAmount logic ─────────────────── */
    // Try string projectId first, then ObjectId
    $brDocs = iterator_to_array(
        $db->budget_requests->find(
            ['projectId' => $projectId],
            [
                'sort'       => ['createdAt' => 1],
                'projection' => ['quotation' => 0]
            ]
        )
    );
    if (empty($brDocs)) {
        try {
            $brDocs = iterator_to_array(
                $db->budget_requests->find(
                    ['projectId' => new MongoDB\BSON\ObjectId($projectId)],
                    [
                        'sort'       => ['createdAt' => 1],
                        'projection' => ['quotation' => 0]
                    ]
                )
            );
        } catch (Exception $e) {}
    }

    // Assign each request to a release group
    $releaseGroups = [];
    foreach ($releaseTimeline as $rt) {
        $releaseGroups[$rt['releaseId']] = array_merge($rt, [
            'heads'          => [],
            'totalEffective' => 0,
            'totalActual'    => 0,
            'totalRawBooked' => 0,
        ]);
    }
    $releaseGroups['__none__'] = [
        'releaseId' => '__none__', 'releaseNumber' => 'Pre-Release',
        'letterNumber' => '', 'letterDate' => '', 'timestamp' => 0,
        'totalReleased' => 0, 'heads' => [],
        'totalEffective' => 0, 'totalActual' => 0, 'totalRawBooked' => 0,
    ];

    $allBR = [];
    $grandEffective = 0;
    $grandActual    = 0;

    foreach ($brDocs as $br) {
        $raw   = $br['createdAt'] ?? null;
        $reqTs = ($raw instanceof MongoDB\BSON\UTCDateTime)
            ? $raw->toDateTime()->getTimestamp()
            : ($raw ? strtotime((string)$raw) : time());

        // Assign to most-recent release before this request
        $assignedId = '__none__';
        $bestTs     = -1;
        foreach ($releaseTimeline as $rt) {
            if ($rt['timestamp'] !== null && $rt['timestamp'] <= $reqTs && $rt['timestamp'] > $bestTs) {
                $bestTs     = $rt['timestamp'];
                $assignedId = $rt['releaseId'];
            }
        }

        $bookedAmt    = floatval($br['requestedAmount'] ?? $br['amount'] ?? 0);
        $actualAmt    = floatval($br['actualExpenditure'] ?? 0);
        $isFilled     = $actualAmt > 0;
        $effectiveAmt = $isFilled ? $actualAmt : $bookedAmt;

        $headId   = (string)($br['headId']   ?? 'unknown');
        $headName = $br['headName'] ?? 'Unknown Head';
        $headType = $br['headType'] ?? '';

        $actualEnteredAt = $fmtDT($br['actualExpenditureEnteredAt'] ?? null);

        // Build approval history
        $history = [];
        foreach (($br['approvalHistory'] ?? []) as $h) {
            $ts2 = $h['timestamp'] ?? $h['actionAt'] ?? $h['createdAt'] ?? null;
            $history[] = [
                'stage'     => $h['stage']  ?? '',
                'action'    => $h['action'] ?? '',
                'by'        => $h['by']     ?? $h['approvedBy'] ?? '',
                'remarks'   => $h['remarks'] ?? '',
                'timestamp' => is_string($ts2) ? $ts2 : $fmtDT($ts2),
            ];
        }

        $entry = [
            'id'                     => (string)$br['_id'],
            'requestNumber'          => $br['requestNumber']  ?? '',
            'headId'                 => $headId,
            'headName'               => $headName,
            'headType'               => $headType,
            'amount'                 => $bookedAmt,
            'effectiveAmount'        => $effectiveAmt,
            'actualExpenditure'      => $actualAmt,
            'actualEnteredAt'        => $actualEnteredAt,
            'expenditureFilled'      => $isFilled,
            'purpose'                => $br['purpose']        ?? '',
            'invoiceNumber'          => $br['invoiceNumber']  ?? '',
            'status'                 => $br['status']         ?? 'pending',
            'currentStage'           => $br['currentStage']  ?? 'da',
            'daRemarks'              => $br['daRemarks']      ?? '',
            'arRemarks'              => $br['arRemarks']      ?? '',
            'drRemarks'              => $br['drRemarks']      ?? '',
            'approvalHistory'        => $history,
            'assignedReleaseId'      => $assignedId,
            'createdAt'              => $fmtDT($raw),
        ];

        $allBR[] = $entry;

        // Accumulate into release group
        if (!isset($releaseGroups[$assignedId]['heads'][$headId])) {
            $releaseGroups[$assignedId]['heads'][$headId] = [
                'headId' => $headId, 'headName' => $headName, 'headType' => $headType,
                'bookedAmount' => 0, 'rawBookedAmount' => 0, 'actualExpenditure' => 0,
                'requests' => [],
            ];
        }
        $releaseGroups[$assignedId]['heads'][$headId]['bookedAmount']      += $effectiveAmt;
        $releaseGroups[$assignedId]['heads'][$headId]['rawBookedAmount']   += $bookedAmt;
        $releaseGroups[$assignedId]['heads'][$headId]['actualExpenditure'] += $actualAmt;
        $releaseGroups[$assignedId]['heads'][$headId]['requests'][]         = $entry;

        $releaseGroups[$assignedId]['totalEffective'] += $effectiveAmt;
        $releaseGroups[$assignedId]['totalActual']    += $actualAmt;
        $releaseGroups[$assignedId]['totalRawBooked'] += $bookedAmt;
    }

    // Format release groups for report
    $formattedReleaseGroups = [];
    foreach ($releaseGroups as $grp) {
        if ($grp['releaseId'] === '__none__' && empty($grp['heads'])) continue;

        $cappedEffective = $grp['totalReleased'] > 0
            ? min($grp['totalEffective'], $grp['totalReleased'])
            : $grp['totalEffective'];

        $grandEffective += $cappedEffective;
        $grandActual    += $grp['totalActual'];

        $formattedReleaseGroups[] = [
            'releaseId'          => $grp['releaseId'],
            'releaseNumber'      => $grp['releaseNumber'],
            'letterNumber'       => $grp['letterNumber'],
            'letterDate'         => $grp['letterDate'],
            'releasedAt'         => $grp['releasedAt']   ?? null,
            'totalReleased'      => $grp['totalReleased'],
            'totalBooked'        => $cappedEffective,
            'totalActual'        => $grp['totalActual'],
            'remainingInRelease' => max(0, $grp['totalReleased'] - $cappedEffective),
            'heads'              => array_values($grp['heads']),
        ];
    }

    $grandAvailable = max(0, $released - $grandEffective);

    /* ── 4. Head-wise allocations ─────────────────────────────────────────── */
    $allocDoc = $db->fund_allocations->findOne(['projectId' => $projectId]);
    $allocations = [];

    if ($allocDoc && isset($allocDoc['allocations'])) {
        foreach ($allocDoc['allocations'] as $alloc) {
            $headId   = (string)($alloc['headId']   ?? '');
            $headName = (string)($alloc['headName'] ?? '');

            // Collect all budget requests for this head
            $headBRs = array_filter($allBR, fn($b) =>
                $b['headId'] === $headId || $b['headName'] === $headName
            );
            $headBooked  = array_sum(array_column(array_values($headBRs), 'effectiveAmount'));
            $headActual  = array_sum(array_column(array_values($headBRs), 'actualExpenditure'));
            $headReleased = floatval($alloc['releasedAmount'] ?? 0);

            // Collect releases for this head from fund_releases
            $headReleaseHistory = [];
            foreach ($releaseHistory as $rel) {
                foreach ($rel['headwiseReleases'] as $hr) {
                    if ($hr['headId'] === $headId || $hr['headName'] === $headName) {
                        $headReleaseHistory[] = [
                            'releaseNumber'  => $rel['releaseNumber'],
                            'letterNumber'   => $rel['letterNumber'],
                            'releasedAt'     => $rel['releasedAt'],
                            'amountReleased' => $hr['releaseAmount'],
                            'newTotal'       => $hr['newTotalReleased'],
                            'releasedBy'     => $rel['releasedBy'],
                            'remarks'        => $rel['remarks'],
                        ];
                    }
                }
            }

            $allocations[] = [
                'headId'           => $headId,
                'headName'         => $headName,
                'headType'         => $alloc['headType']         ?? '',
                'sanctionedAmount' => floatval($alloc['sanctionedAmount'] ?? 0),
                'releasedAmount'   => $headReleased,
                'bookedAmount'     => $headBooked,
                'actualExpenditure'=> $headActual,
                'availableBalance' => max(0, $headReleased - $headBooked),
                'timePeriod'       => $alloc['timePeriod'] ?? '',
                'status'           => $alloc['status']     ?? 'sanctioned',
                'releases'         => $headReleaseHistory,
                'bookings'         => array_values($headBRs),
            ];
        }
    }

    /* ── 5. Extensions ────────────────────────────────────────────────────── */
    $extensions = [];
    try {
        $extDocs = iterator_to_array(
            $db->project_extensions->find(
                ['projectId' => $projectId],
                ['sort' => ['createdAt' => 1]]
            )
        );
        if (empty($extDocs)) {
            try {
                $extDocs = iterator_to_array(
                    $db->project_extensions->find(
                        ['projectId' => new MongoDB\BSON\ObjectId($projectId)],
                        ['sort' => ['createdAt' => 1]]
                    )
                );
            } catch (Exception $e) {}
        }
        foreach ($extDocs as $ext) {
            $extensions[] = [
                'originalEndDate'  => $fmtPlainDate($ext['originalEndDate']  ?? null),
                'extendedEndDate'  => $fmtPlainDate($ext['extendedEndDate']  ?? null),
                'additionalYears'  => floatval($ext['additionalYears'] ?? 0),
                'remarks'          => $ext['remarks']    ?? '',
                'extendedBy'       => $ext['extendedBy'] ?? '',
                'extendedAt'       => $fmtDT($ext['extendedAt'] ?? $ext['createdAt'] ?? null),
                'hasPdf'           => !empty($ext['extensionPdfPath']),
                'pdfOriginalName'  => $ext['extensionPdfOriginalName'] ?? '',
            ];
        }
    } catch (Exception $e) {
        error_log('Extensions: ' . $e->getMessage());
    }

    /* ── 6. Summary ───────────────────────────────────────────────────────── */
    $summary = [
        'totalSanctioned'    => $sanctioned,
        'totalReleased'      => $released,
        'unreleasedAmount'   => $sanctioned - $released,
        'totalBooked'        => $grandEffective,
        'totalActual'        => $grandActual,
        'piBalance'          => $grandAvailable,
        'utilizationPct'     => $released > 0 ? round(($grandEffective / $released) * 100, 1) : 0,
        'totalRequests'      => count($allBR),
        'approvedRequests'   => count(array_filter($allBR, fn($b) => $b['status'] === 'approved')),
        'pendingRequests'    => count(array_filter($allBR, fn($b) => in_array($b['status'], ['pending','da_approved','ar_approved']))),
        'rejectedRequests'   => count(array_filter($allBR, fn($b) => $b['status'] === 'rejected')),
        'totalReleases'      => count($releaseHistory),
        'totalExtensions'    => count($extensions),
    ];

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'data'    => [
            'project'        => $projectData,
            'allocations'    => $allocations,
            'releaseHistory' => $releaseHistory,
            'releaseGroups'  => $formattedReleaseGroups,
            'budgetRequests' => $allBR,
            'extensions'     => $extensions,
            'summary'        => $summary,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    ob_end_clean();
    error_log("get-project-report v6: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>