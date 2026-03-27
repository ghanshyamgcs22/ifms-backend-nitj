<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// Catch fatal errors (e.g., missing vendor/autoload.php) and return as JSON
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $err['message'], 'file' => $err['file'], 'line' => $err['line']]);
    }
});

require_once __DIR__ . '/../config/database.php';

$stage = $_GET['stage'] ?? '';
$type  = $_GET['type']  ?? 'pending';

try {
    $db         = getMongoDBConnection();
    $collection = $db->budget_requests;

    $requestId = $_GET['requestId'] ?? null;

    if ($requestId) {
        // Single request fetch - ignore everything else
        try {
            $filter = ['_id' => new \MongoDB\BSON\ObjectId((string)$requestId)];
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Invalid Request ID']);
            exit();
        }
    } elseif ($type === 'completed') {
        $filter = ['status' => ['$in' => ['approved', 'rejected']]];

    } elseif ($stage === 'all' || $type === 'all') {
        $filter = [];

    } elseif ($type === 'pending') {
        $stageStatusMap = [
            'da' => ['currentStage' => 'da', 'status' => 'pending'],
            'ar' => ['currentStage' => 'ar', 'status' => 'da_approved'],
            'dr' => ['currentStage' => 'dr', 'status' => 'ar_approved'],
        ];

        if (isset($stageStatusMap[$stage])) {
            $filter = $stageStatusMap[$stage];
        } else {
            $filter = [
                'currentStage' => ['$in' => ['ar', 'dr']],
                'status'       => ['$nin' => ['approved', 'rejected']],
            ];
        }
    } else {
        $filter = [];
    }

    $limit   = intval($_GET['limit']   ?? 0);
    $offset  = intval($_GET['offset']  ?? 0);
    $summary = ($_GET['summary'] ?? '0') === '1';

    $options = [
        'sort' => ['createdAt' => -1],
    ];
    if ($limit > 0) {
        $options['limit'] = $limit;
        $options['skip']  = $offset;
    }

    if ($summary) {
        $options['projection'] = [
            'quotation' => 0, 'approvalHistory' => 0, 'description' => 0, 
            'material' => 0, 'mode' => 0, 'remarks' => 0,
            'daRemarks' => 0, 'arRemarks' => 0, 'drRemarks' => 0,
            'drcOfficeRemarks' => 0, 'drcRcRemarks' => 0, 'drcRemarks' => 0, 'directorRemarks' => 0
        ];
    } else {
        $options['projection'] = ['quotation' => 0];
    }

    $cursor  = $collection->find($filter, $options);
    $rawResults = iterator_to_array($cursor);

    // ── 1. Batch Fetch Project & Head Allocations ──────────────────
    $pIds = array_unique(array_filter(array_map(fn($r) => (string)($r['projectId'] ?? ''), $rawResults)));
    $projectMap = [];
    if (!empty($pIds)) {
        $objIds = [];
        foreach ($pIds as $id) { 
            try { $objIds[] = new \MongoDB\BSON\ObjectId((string)$id); } 
            catch (Exception $e) {} 
        }

        if (!empty($objIds)) {
            // Fetch projects
            $projects = $db->projects->find(['_id' => ['$in' => $objIds]], ['projection' => ['projectName' => 1, 'projectEndDate' => 1, 'totalSanctionedAmount' => 1]]);
            foreach ($projects as $p) {
                $pid = (string)$p['_id'];
                $endDate = $p['projectEndDate'] ?? null;
                $sanctioned = floatval($p['totalSanctionedAmount'] ?? 0);
                
                $projectMap[$pid] = [
                    'name'    => $p['projectName'] ?? '',
                    'endDate' => ($endDate instanceof MongoDB\BSON\UTCDateTime) ? $endDate->toDateTime()->format('Y-m-d') : ($endDate ?? ''),
                    'sanctionedAmount' => $sanctioned,
                    'heads' => []
                ];
            }

            // Fetch head allocations for these projects to get head-specific sanctioned amounts
            $pIdStrings = array_map('strval', array_values($pIds));
            $headAllocs = $db->head_allocations->find(['projectId' => ['$in' => $pIdStrings]]);
            foreach ($headAllocs as $ha) {
                $pid = (string)$ha['projectId'];
                $hId = (string)($ha['headId'] ?? '');
                $hName = (string)($ha['headName'] ?? '');
                if (isset($projectMap[$pid])) {
                    $key = $hId ?: $hName;
                    
                    // Sanction Limit should show released amount if available
                    $sanctioned = floatval($ha['releasedAmount'] ?? 0);
                    if ($sanctioned <= 0) $sanctioned = floatval($ha['sanctionedAmount'] ?? 0);

                    $projectMap[$pid]['heads'][$key] = [
                        'sanctioned' => $sanctioned,
                        'booked' => floatval($ha['bookedAmount'] ?? 0),
                        'type' => $ha['headType'] ?? ''
                    ];
                }
            }
        }
    }

    $result = [];
    foreach ($rawResults as $r) {
        $amount = floatval($r['requestedAmount'] ?? $r['amount'] ?? 0);
        $pid    = (string)($r['projectId'] ?? '');

        $approvalHistory = isset($r['approvalHistory'])
            ? array_values(iterator_to_array($r['approvalHistory']))
            : [];

        // Compute latestRemark from history
        $latestRemark = '';
        if (!empty($approvalHistory)) {
            for ($i = count($approvalHistory) - 1; $i >= 0; $i--) {
                $h = (array)$approvalHistory[$i];
                if (!empty($h['remarks'])) {
                    $latestRemark = $h['remarks'];
                    break;
                }
            }
        }

        $hId    = (string)($r['headId']   ?? '');
        $hName  = (string)($r['headName'] ?? '');
        $hKey    = $hId ?: $hName;
        $projData = $projectMap[$pid] ?? ['name' => '', 'endDate' => '', 'sanctionedAmount' => 0, 'heads' => []];
        $headDetails = $projData['heads'][$hKey] ?? ['sanctioned' => 0, 'booked' => 0];

        $result[] = [
            'id'              => (string)($r['_id']),
            'requestNumber'   => $r['requestNumber']   ?? '',
            'projectId'       => $pid,
            'gpNumber'        => $r['gpNumber']        ?? '',
            'projectTitle'    => $r['projectTitle']    ?? $projData['name'] ?? '',
            'piName'          => $r['piName']          ?? '',
            'piEmail'         => $r['piEmail']         ?? '',
            'department'      => $r['department']      ?? '',
            'purpose'         => $r['purpose']         ?? '',
            'description'     => $r['description']     ?? '',
            'amount'          => $amount,
            'requestedAmount' => $amount,
            'projectType'     => $r['projectType']     ?? '',
            'invoiceNumber'   => $r['invoiceNumber']   ?? '',
            'material'        => $r['material']        ?? '',
            'mode'            => $r['mode']            ?? '',
            'fileNumber'      => $r['fileNumber']      ?? '',
            'projectEndDate'  => $projData['endDate'] ?: ($r['projectCompletionDate'] ?? ''),
            'totalSanctionedAmount' => floatval($projData['sanctionedAmount'] ?? 0),
            'headId'          => $hId,
            'headName'        => $hName,
            'headType'        => $r['headType']        ?? '',
            'headSanctionedAmount' => floatval($headDetails['sanctioned']),
            'headBookedAmount'     => floatval($headDetails['booked']),
            'status'          => $r['status']          ?? 'pending',
            'currentStage'    => $r['currentStage']    ?? 'da',
            'daRemarks'       => $r['daRemarks']       ?? '',
            'arRemarks'       => $r['arRemarks']       ?? '',
            'drRemarks'       => $r['drRemarks']       ?? '',
            'drcOfficeRemarks' => $r['drcOfficeRemarks'] ?? '',
            'drcRcRemarks'     => $r['drcRcRemarks']     ?? '',
            'drcRemarks'       => $r['drcRemarks']       ?? '',
            'directorRemarks'  => $r['directorRemarks']  ?? '',
            'actualExpenditure' => floatval($r['actualExpenditure'] ?? 0),
            'approvalHistory' => $approvalHistory,
            'latestRemark'    => $latestRemark,
            'createdAt'       => isset($r['createdAt'])
                ? $r['createdAt']->toDateTime()->format('Y-m-d H:i:s') : '',
            'updatedAt'       => isset($r['updatedAt'])
                ? $r['updatedAt']->toDateTime()->format('Y-m-d H:i:s') : '',
        ];
    }

    echo json_encode(['success' => true, 'data' => $result, 'count' => count($result)]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}
?>