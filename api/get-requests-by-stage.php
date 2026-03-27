<?php
// api/get-requests-by-stage.php
// Returns ALL fields including material (pt7), expenditure (pt7), mode (pt8),
// quotationFile base64, fileNumber, all stage remarks, latestQuery, approvalHistory.
//
// Sendback statuses per stage (what each stage sees as "sent back to me"):
//   da         ← sent_back_to_da         (from AR)
//   ar         ← sent_back_to_ar         (from DR)
//   dr         ← sent_back_to_dr         (from DRC Office)
//   drc_office ← sent_back_to_drc_office (from DR (R&C))
//   drc_rc     ← sent_back_to_drc_rc     (from DRC)
//   drc        ← sent_back_to_drc        (from Director)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
require_once __DIR__ . '/../config/database.php';

// Catch fatal errors and return as JSON
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $err['message'], 'file' => $err['file'], 'line' => $err['line']]);
        exit();
    }
});

$stage    = $_GET['stage']    ?? '';
$type     = $_GET['type']     ?? 'pending';
$withFile = ($_GET['withFile'] ?? '1') !== '0';

// ── What status does each stage receive when sent back TO it ──────────────────
$sentBackToMe = [
    'da'         => 'sent_back_to_da',
    'ar'         => 'sent_back_to_ar',
    'dr'         => 'sent_back_to_dr',
    'drc_office' => 'sent_back_to_drc_office',
    'drc_rc'     => 'sent_back_to_drc_rc',
    'drc'        => 'sent_back_to_drc',
    // director never receives a sendback
];

// ── What status means "I (this stage) sent it back to the previous stage" ─────
$sentBackByMe = [
    'ar'         => 'sent_back_to_da',
    'dr'         => 'sent_back_to_ar',
    'drc_office' => 'sent_back_to_dr',
    'drc_rc'     => 'sent_back_to_drc_office',
    'drc'        => 'sent_back_to_drc_rc',
    'director'   => 'sent_back_to_drc',
];

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
        // Globally approved or rejected
        $filter = ['status' => ['$in' => ['approved', 'rejected']]];

    } elseif ($stage === 'all' || $type === 'all') {
        $filter = [];

    } elseif ($type === 'sentback') {
        // "sentback" type = requests THIS stage sent back to the previous stage
        // e.g. DR dashboard sentback tab = requests DR sent back to AR
        if (isset($sentBackByMe[$stage])) {
            $filter = [
                'status'    => $sentBackByMe[$stage],
                // The request is now sitting at the previous stage, NOT at $stage
                // We don't filter by currentStage here because it has already moved back
            ];
        } else {
            // DA has no sentback-by-me; director's sentback = sent_back_to_drc
            $filter = ['status' => ['$regex' => '^sent_back_to_']];
        }

    } elseif ($type === 'pending') {
        // "pending" = requests currently at THIS stage waiting for action
        // Includes: normal incoming status AND sent-back-to-this-stage (returned to me)
        $stageFilters = [
            'da' => ['$or' => [
                ['currentStage' => 'da', 'status' => 'pending'],
                ['currentStage' => 'da', 'status' => 'sent_back_to_da'],   // returned from AR
            ]],
            'ar' => ['$or' => [
                ['currentStage' => 'ar', 'status' => 'da_approved'],
                ['currentStage' => 'ar', 'status' => 'sent_back_to_ar'],   // returned from DR
            ]],
            'dr' => ['$or' => [
                ['currentStage' => 'dr', 'status' => 'ar_approved'],
                ['currentStage' => 'dr', 'status' => 'sent_back_to_dr'],   // returned from DRC Office
            ]],
            'drc_office' => ['$or' => [
                ['currentStage' => 'drc_office', 'status' => 'dr_approved'],
                ['currentStage' => 'drc_office', 'status' => 'sent_back_to_drc_office'], // returned from DRC R&C
            ]],
            'drc_rc' => ['$or' => [
                ['currentStage' => 'drc_rc', 'status' => 'drc_office_forwarded'],
                ['currentStage' => 'drc_rc', 'status' => 'sent_back_to_drc_rc'],  // returned from DRC
            ]],
            'drc' => ['$or' => [
                ['currentStage' => 'drc', 'status' => 'drc_rc_forwarded'],
                ['currentStage' => 'drc', 'status' => 'sent_back_to_drc'],  // returned from Director
            ]],
            'director' => ['$or' => [
                ['currentStage' => 'director', 'status' => 'drc_forwarded'],
                // Director never receives a sendback
            ]],
        ];
        $filter = $stageFilters[$stage] ?? [];

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
    $pMap = [];
    if (!empty($pIds)) {
        $objIds = [];
        foreach ($pIds as $id) { 
            try { $objIds[] = new \MongoDB\BSON\ObjectId((string)$id); } 
            catch(Exception $e){} 
        }
        
        if (!empty($objIds)) {
            $projs = $db->projects->find(['_id' => ['$in' => $objIds]], ['projection' => ['projectName' => 1, 'projectEndDate' => 1, 'totalSanctionedAmount' => 1]]);
            foreach ($projs as $p) {
                $pid = (string)$p['_id'];
                $ed  = $p['projectEndDate'] ?? '';
                $sanctioned = floatval($p['totalSanctionedAmount'] ?? 0);
                
                $pMap[$pid] = [
                    'name'    => $p['projectName'] ?? '',
                    'endDate' => ($ed instanceof \MongoDB\BSON\UTCDateTime) ? $ed->toDateTime()->format('Y-m-d') : $ed,
                    'sanctionedAmount' => $sanctioned,
                    'heads' => []
                ];
            }

            // Fetch head allocations for these projects
            $pIdStrings = array_map('strval', array_values($pIds));
            $headAllocs = $db->head_allocations->find(['projectId' => ['$in' => $pIdStrings]]);
            foreach ($headAllocs as $ha) {
                $pIdStr = (string)$ha['projectId'];
                $hId    = (string)($ha['headId'] ?? '');
                $hName  = (string)($ha['headName'] ?? '');
                if (isset($pMap[$pIdStr])) {
                    $key = $hId ?: $hName;
                    // Sanction Limit should show released amount if available
                    $sanctioned = floatval($ha['releasedAmount'] ?? 0);
                    if ($sanctioned <= 0) $sanctioned = floatval($ha['sanctionedAmount'] ?? 0);
                    
                    $pMap[$pIdStr]['heads'][$key] = [
                        'sanctioned' => $sanctioned,
                        'booked'     => floatval($ha['bookedAmount'] ?? 0),
                        'type'       => $ha['headType'] ?? ''
                    ];
                }
            }
        }
    }

    $result = [];
    foreach ($rawResults as $r) {
        $pid    = (string)($r['projectId'] ?? '');
        $amount = floatval($r['requestedAmount'] ?? $r['amount'] ?? 0);

        $history = [];
        if (!empty($r['approvalHistory'])) {
            foreach ($r['approvalHistory'] as $h) {
                $h = is_array($h) ? $h : (array)$h;
                $history[] = [
                    'stage'     => (string)($h['stage']     ?? ''),
                    'action'    => (string)($h['action']    ?? ''),
                    'by'        => (string)($h['by']        ?? ''),
                    'timestamp' => (string)($h['timestamp'] ?? ''),
                    'remarks'   => (string)($h['remarks']   ?? ''),
                ];
            }
        }

        $latestQuery = null;
        if (!empty($r['latestQuery'])) {
            $lq = is_array($r['latestQuery']) ? $r['latestQuery'] : (array)$r['latestQuery'];
            $latestQuery = [
                'query'         => (string)($lq['query']         ?? ''),
                'raisedBy'      => (string)($lq['raisedBy']      ?? ''),
                'raisedByLabel' => (string)($lq['raisedByLabel'] ?? ''),
                'raisedAt'      => (string)($lq['raisedAt']      ?? ''),
                'raisedStage'   => (string)($lq['raisedStage']   ?? ''),
                'resolved'      => (bool)($lq['resolved']        ?? false),
                'piResponse'    => (string)($lq['piResponse']    ?? ''),
            ];
        }

        $quotationFile     = '';
        $quotationFileName = (string)($r['quotationFileName'] ?? '');
        if ($withFile && !empty($r['quotation'])) {
            $quotationFile = (string)$r['quotation'];
        }
        if (empty($quotationFileName)) {
            $gp = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($r['gpNumber'] ?? ''));
            $quotationFileName = "Quotation_{$gp}.pdf";
        }

        $currentStatus = (string)($r['status'] ?? 'pending');

        $reqHeadId   = (string)($r['headId'] ?? '');
        $reqHeadName = (string)($r['headName'] ?? '');
        $hKey        = $reqHeadId ?: $reqHeadName;
        $headSanctioned = $pMap[$pid]['heads'][$hKey]['sanctioned'] ?? 0;
        $headBooked     = $pMap[$pid]['heads'][$hKey]['booked'] ?? 0;

        $result[] = [
            'id'               => (string)($r['_id']),
            'requestNumber'    => (string)($r['requestNumber']    ?? ''),
            'projectId'        => $pid,
            'gpNumber'         => (string)($r['gpNumber']         ?? ''),
            'fileNumber'       => (string)($r['fileNumber']       ?? ''),
            'projectTitle'     => (string)($r['projectTitle']     ?? $pMap[$pid]['name'] ?? ''),
            'piName'           => (string)($r['piName']           ?? ''),
            'piEmail'          => (string)($r['piEmail']          ?? ''),
            'department'       => (string)($r['department']       ?? ''),
            'headId'           => $reqHeadId,
            'headName'         => $reqHeadName,
            'headType'         => (string)($r['headType']         ?? ''),
            'projectType'      => (string)($r['projectType']      ?? ''),
            'amount'           => $amount,
            'requestedAmount'  => $amount,
            'projectEndDate'   => $pMap[$pid]['endDate'] ?? ($r['projectCompletionDate'] ?? ''),
            'totalSanctionedAmount' => floatval($pMap[$pid]['sanctionedAmount'] ?? 0),
            'headSanctionedAmount' => floatval($headSanctioned),
            'headBookedAmount'     => floatval($headBooked),
            'actualExpenditure'=> floatval($r['actualExpenditure'] ?? 0),
            'invoiceNumber'    => (string)($r['invoiceNumber']    ?? ''),
            'purpose'          => (string)($r['purpose']          ?? ''),
            'description'      => (string)($r['description']      ?? ''),
            'material'         => (string)($r['material']         ?? ''),
            'expenditure'      => (string)($r['expenditure']      ?? ''),
            'mode'             => (string)($r['mode']             ?? ''),
            'quotationFile'    => $quotationFile,
            'quotationFileName'=> $quotationFileName,
            'status'           => $currentStatus,
            'previousStatus'   => (string)($r['previousStatus']   ?? ''),
            'currentStage'     => (string)($r['currentStage']     ?? 'da'),
            'hasOpenQuery'     => (bool)($r['hasOpenQuery']        ?? false),
            // isSentBack = this request was sent back TO me (returned for re-work)
            'isSentBack'       => str_starts_with($currentStatus, 'sent_back_to_'),
            // All stage remarks
            'daRemarks'        => (string)($r['daRemarks']        ?? ''),
            'arRemarks'        => (string)($r['arRemarks']        ?? ''),
            'drRemarks'        => (string)($r['drRemarks']        ?? ''),
            'drcOfficeRemarks' => (string)($r['drcOfficeRemarks'] ?? ''),
            'drcRcRemarks'     => (string)($r['drcRcRemarks']     ?? ''),
            'drcRemarks'       => (string)($r['drcRemarks']       ?? ''),
            'directorRemarks'  => (string)($r['directorRemarks']  ?? ''),
            'latestQuery'      => $latestQuery,
            'approvalHistory'  => $history,
            'latestRemark'     => (function($hist) {
                if (empty($hist)) return '';
                for ($i = count($hist) - 1; $i >= 0; $i--) {
                    if (!empty($hist[$i]['remarks'])) return $hist[$i]['remarks'];
                }
                return '';
            })($history),
            'approvalType'     => (string)($r['approvalType']     ?? ''),
            'rejectedBy'           => (string)($r['rejectedBy']           ?? ''),
            'rejectedAtStage'      => (string)($r['rejectedAtStage']      ?? ''),
            'rejectedAtStageLabel' => (string)($r['rejectedAtStageLabel'] ?? ''),
            'rejectionRemarks'     => (string)($r['rejectionRemarks']     ?? ''),
            'createdAt' => isset($r['createdAt']) ? $r['createdAt']->toDateTime()->format('Y-m-d H:i:s') : '',
            'updatedAt' => isset($r['updatedAt']) ? $r['updatedAt']->toDateTime()->format('Y-m-d H:i:s') : '',
        ];
    }

    echo json_encode(['success' => true, 'data' => $result, 'count' => count($result)]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>