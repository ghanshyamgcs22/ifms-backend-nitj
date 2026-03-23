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
        'piEmail'             => $piEmail,
        'status'              => ['$nin' => ['rejected', 'completed']],
    ]);

    $projects = [];

    foreach ($cursor as $project) {
        $projectId  = (string) $project['_id'];
        $released   = floatval($project['totalReleasedAmount']   ?? 0);
        $sanctioned = floatval($project['totalSanctionedAmount'] ?? 0);

        // ── 1. Derive amountBookedByPI live from budget_requests ──────────────
        // Sum requestedAmount for all non-rejected requests.
        $bookingPipeline = [
            [
                '$match' => [
                    'projectId' => $projectId,
                    'status'    => ['$nin' => ['rejected']],
                ]
            ],
            [
                '$group' => [
                    '_id'   => null,
                    'total' => ['$sum' => '$requestedAmount'],
                ]
            ]
        ];
        $bookingResult = iterator_to_array($db->budget_requests->aggregate($bookingPipeline));
        $booked = isset($bookingResult[0]) ? floatval($bookingResult[0]['total']) : 0;

        // ── 2. Derive actualExpenditure live from budget_requests ─────────────
        // Sum actualExpenditure on approved requests where DA has entered it.
        $actualPipeline = [
            [
                '$match' => [
                    'projectId'         => $projectId,
                    'status'            => 'approved',
                    'actualExpenditure' => ['$gt' => 0],
                ]
            ],
            [
                '$group' => [
                    '_id'   => null,
                    'total' => ['$sum' => '$actualExpenditure'],
                ]
            ]
        ];
        $actualResult = iterator_to_array($db->budget_requests->aggregate($actualPipeline));
        $actual = isset($actualResult[0]) ? floatval($actualResult[0]['total']) : 0;

        // ── 3. Sync corrected values back onto the project document ───────────
        $db->projects->updateOne(
            ['_id' => $project['_id']],
            ['$set' => [
                'amountBookedByPI'  => $booked,
                'actualExpenditure' => $actual,
                'updatedAt'         => new MongoDB\BSON\UTCDateTime(),
            ]]
        );

        // ── 4. Project-level available balance ────────────────────────────────
        // Formula: Released - Booked + (Booked - Actual)
        // Simplified: Released - Actual  (but floored per component)
        $unusedBooking    = max(0, $booked - $actual);
        $availableBalance = max(0, $released - $booked + $unusedBooking);

        // ── 5. Head-level data from head_allocations ──────────────────────────
        $headAllocsCursor = $db->head_allocations->find(['projectId' => $projectId]);
        $heads = [];

        foreach ($headAllocsCursor as $alloc) {
            $headReleased = floatval($alloc['releasedAmount'] ?? 0);
            if ($headReleased <= 0) continue;

            $headSanctioned = floatval($alloc['sanctionedAmount'] ?? 0);
            $headId         = (string)($alloc['headId']   ?? '');
            $headName       = (string)($alloc['headName'] ?? '');

            // ── Re-derive head booked from budget_requests ────────────────────
            // Match by BOTH headId AND headName to handle any ID mismatch
            $headBookingPipeline = [
                [
                    '$match' => [
                        'projectId' => $projectId,
                        'status'    => ['$nin' => ['rejected']],
                        '$or'       => [
                            ['headId'   => $headId],
                            ['headName' => $headName],
                        ],
                    ]
                ],
                [
                    '$group' => [
                        '_id'   => null,
                        'total' => ['$sum' => '$requestedAmount'],
                    ]
                ]
            ];
            $headBookingResult = iterator_to_array(
                $db->budget_requests->aggregate($headBookingPipeline)
            );
            // Fall back to stored bookedAmount only if query returns nothing
            $headBooked = isset($headBookingResult[0])
                ? floatval($headBookingResult[0]['total'])
                : floatval($alloc['bookedAmount'] ?? 0);

            // ── Re-derive head actual expenditure from budget_requests ─────────
            $headActualPipeline = [
                [
                    '$match' => [
                        'projectId'         => $projectId,
                        'status'            => 'approved',
                        'actualExpenditure' => ['$gt' => 0],
                        '$or'               => [
                            ['headId'   => $headId],
                            ['headName' => $headName],
                        ],
                    ]
                ],
                [
                    '$group' => [
                        '_id'   => null,
                        'total' => ['$sum' => '$actualExpenditure'],
                    ]
                ]
            ];
            $headActualResult = iterator_to_array(
                $db->budget_requests->aggregate($headActualPipeline)
            );
            // Fall back to stored actualExpenditure only if query returns nothing
            $headActual = isset($headActualResult[0])
                ? floatval($headActualResult[0]['total'])
                : floatval($alloc['actualExpenditure'] ?? 0);

            // ── Head available balance ─────────────────────────────────────────
            // Released - Booked + (Booked - Actual)  [unused booking buffer]
            $headUnused    = max(0, $headBooked - $headActual);
            $headAvailable = max(0, $headReleased - $headBooked + $headUnused);

            // ── Sync corrected values back to head_allocations ────────────────
            $db->head_allocations->updateOne(
                ['_id' => $alloc['_id']],
                ['$set' => [
                    'bookedAmount'      => $headBooked,
                    'actualExpenditure' => $headActual,
                    'updatedAt'         => new MongoDB\BSON\UTCDateTime(),
                ]]
            );

            $heads[] = [
                'id'               => (string)($alloc['_id'] ?? ''),
                'headId'           => $headId,
                'headName'         => $headName,
                'headType'         => $alloc['headType'] ?? '',
                'sanctionedAmount' => $headSanctioned,
                'releasedAmount'   => $headReleased,
                'bookedAmount'     => $headBooked,
                'actualExpenditure'=> $headActual,
                'availableBalance' => $headAvailable,
            ];
        }

        // ── Fallback: fund_allocations for legacy projects ────────────────────
        // Used only when head_allocations has no records for this project
        if (empty($heads)) {
            $allocDoc = $db->fund_allocations->findOne(['projectId' => $projectId]);
            if ($allocDoc && isset($allocDoc['allocations'])) {
                foreach ($allocDoc['allocations'] as $alloc) {
                    $headReleased = floatval($alloc['releasedAmount'] ?? 0);
                    if ($headReleased <= 0) continue;

                    $headId   = (string)($alloc['headId']   ?? '');
                    $headName = (string)($alloc['headName'] ?? '');

                    // Same live-query logic for legacy path
                    $hbPipeline = [
                        ['$match' => [
                            'projectId' => $projectId,
                            'status'    => ['$nin' => ['rejected']],
                            '$or'       => [
                                ['headId'   => $headId],
                                ['headName' => $headName],
                            ],
                        ]],
                        ['$group' => ['_id' => null, 'total' => ['$sum' => '$requestedAmount']]],
                    ];
                    $hbResult   = iterator_to_array($db->budget_requests->aggregate($hbPipeline));
                    $headBooked = isset($hbResult[0])
                        ? floatval($hbResult[0]['total'])
                        : floatval($alloc['bookedAmount'] ?? 0);

                    $haPipeline = [
                        ['$match' => [
                            'projectId'         => $projectId,
                            'status'            => 'approved',
                            'actualExpenditure' => ['$gt' => 0],
                            '$or'               => [
                                ['headId'   => $headId],
                                ['headName' => $headName],
                            ],
                        ]],
                        ['$group' => ['_id' => null, 'total' => ['$sum' => '$actualExpenditure']]],
                    ];
                    $haResult   = iterator_to_array($db->budget_requests->aggregate($haPipeline));
                    $headActual = isset($haResult[0])
                        ? floatval($haResult[0]['total'])
                        : floatval($alloc['actualExpenditure'] ?? 0);

                    $headSanctioned = floatval($alloc['sanctionedAmount'] ?? 0);
                    $headUnused     = max(0, $headBooked - $headActual);
                    $headAvailable  = max(0, $headReleased - $headBooked + $headUnused);

                    $heads[] = [
                        'id'               => (string)($alloc['_id'] ?? $alloc['id'] ?? ''),
                        'headId'           => $headId,
                        'headName'         => $headName,
                        'headType'         => $alloc['headType'] ?? '',
                        'sanctionedAmount' => $headSanctioned,
                        'releasedAmount'   => $headReleased,
                        'bookedAmount'     => $headBooked,
                        'actualExpenditure'=> $headActual,
                        'availableBalance' => $headAvailable,
                    ];
                }
            }
        }

        // ── Format dates safely ───────────────────────────────────────────────
        $formatDate = function ($val) {
            if ($val instanceof MongoDB\BSON\UTCDateTime) {
                return $val->toDateTime()->format('Y-m-d');
            }
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
            'totalSanctionedAmount' => $sanctioned,
            'totalReleasedAmount'   => $released,
            // Live-computed — never stale:
            'amountBookedByPI'      => $booked,
            'actualExpenditure'     => $actual,
            'availableBalance'      => $availableBalance,
            'status'                => $project['status'] ?? 'active',
            'heads'                 => $heads,
        ];
    }

    echo json_encode([
        'success' => true,
        'data'    => $projects,
        'count'   => count($projects),
    ]);

} catch (Exception $e) {
    error_log("get-pi-projects error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>