<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';

$stage = $_GET['stage'] ?? '';
$type  = $_GET['type']  ?? 'pending';

try {
    $db         = getMongoDBConnection();
    $collection = $db->budget_requests;

    if ($type === 'completed') {
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

    $options = [
        'sort' => ['createdAt' => -1],
        'projection' => ['quotation' => 0] // Exclude large base64 field from listing
    ];
    $cursor  = $collection->find($filter, $options);
    $rawResults = iterator_to_array($cursor);

    // ── 1. Batch Fetch Project End Dates ──────────────────
    $projectIds = array_unique(array_filter(array_map(fn($r) => $r['projectId'] ?? null, $rawResults)));
    $projectMap = [];
    if (!empty($projectIds)) {
        $objIds = [];
        foreach ($projectIds as $id) {
            try { $objIds[] = new MongoDB\BSON\ObjectId($id); } catch (Exception $e) {}
        }
        if (!empty($objIds)) {
            // Fetch projects
            $projects = $db->projects->find(['_id' => ['$in' => $objIds]], ['projection' => ['projectEndDate' => 1, 'totalSanctionedAmount' => 1]]);
            foreach ($projects as $p) {
                $pid = (string)$p['_id'];
                $endDate = $p['projectEndDate'] ?? null;
                $sanctioned = floatval($p['totalSanctionedAmount'] ?? 0);
                
                $projectMap[$pid] = [
                    'endDate' => ($endDate instanceof MongoDB\BSON\UTCDateTime) ? $endDate->toDateTime()->format('Y-m-d') : ($endDate ?? ''),
                    'sanctionedAmount' => $sanctioned,
                    'heads' => []
                ];
            }

            // Fetch head allocations for these projects to get head-specific sanctioned amounts
            $headAllocs = $db->head_allocations->find(['projectId' => ['$in' => $projectIds]]);
            foreach ($headAllocs as $ha) {
                $pid = (string)$ha['projectId'];
                $hId = (string)($ha['headId'] ?? '');
                $hName = (string)($ha['headName'] ?? '');
                if (isset($projectMap[$pid])) {
                    $key = $hId ?: $hName;
                    $projectMap[$pid]['heads'][$key] = [
                        'sanctioned' => floatval($ha['sanctionedAmount'] ?? 0),
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
        $headDetails = $projectMap[$pid]['heads'][$hKey] ?? ['sanctioned' => 0, 'booked' => 0];

        $result[] = [
            'id'              => (string)($r['_id']),
            'requestNumber'   => $r['requestNumber']   ?? '',
            'projectId'       => $pid,
            'gpNumber'        => $r['gpNumber']        ?? '',
            'projectTitle'    => $r['projectTitle']    ?? '',
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
            'projectEndDate'  => $projectMap[$pid]['endDate'] ?? $r['projectCompletionDate'] ?? '',
            'totalSanctionedAmount' => $projectMap[$pid]['sanctionedAmount'] ?? 0,
            'headId'          => $hId,
            'headName'        => $hName,
            'headType'        => $r['headType']        ?? '',
            'headSanctionedAmount' => $headDetails['sanctioned'],
            'headBookedAmount'     => $headDetails['booked'],
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

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>