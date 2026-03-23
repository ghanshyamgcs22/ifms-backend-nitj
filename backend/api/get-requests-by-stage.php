<?php
// api/get-requests-by-stage.php — WITH REJECTION + FILE NUMBER FIELDS
// Returns rejectedAtStage, rejectedBy, rejectedAt, fileNumber for all requests

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';

$stage = trim($_GET['stage'] ?? '');
$type  = trim($_GET['type']  ?? 'pending');

if (!$stage) {
    echo json_encode(['success' => false, 'message' => 'stage parameter required']);
    exit();
}

// ── Pending filters — includes query_raised ───────────────────────────────────
$pendingFilters = [
    'da'         => ['currentStage' => 'da',         'status' => ['$in' => ['pending',             'query_raised']]],
    'ar'         => ['currentStage' => 'ar',         'status' => ['$in' => ['da_approved',          'query_raised']]],
    'dr'         => ['currentStage' => 'dr',         'status' => ['$in' => ['ar_approved',          'query_raised']]],
    'drc_office' => ['currentStage' => 'drc_office', 'status' => ['$in' => ['dr_approved',          'query_raised']]],
    'drc_rc'     => ['currentStage' => 'drc_rc',     'status' => ['$in' => ['drc_office_forwarded', 'sent_back_to_drc_rc', 'query_raised']]],
    'drc'        => ['currentStage' => 'drc',        'status' => ['$in' => ['drc_rc_forwarded',     'sent_back_to_drc',    'query_raised']]],
    'director'   => ['currentStage' => 'director',   'status' => ['$in' => ['drc_forwarded',        'query_raised']]],
];

// ── Completed filters ─────────────────────────────────────────────────────────
// Each stage's completed tab shows:
//   - Requests that moved past this stage (approved/forwarded further)
//   - Requests that were REJECTED at this specific stage
$completedFilters = [
    'da' => ['$or' => [
        ['status' => ['$in' => ['da_approved','ar_approved','dr_approved','approved',
                                'drc_office_forwarded','drc_rc_forwarded','drc_forwarded',
                                'sent_back_to_drc_rc','sent_back_to_drc']]],
        ['status' => 'rejected', 'rejectedAtStage' => 'da'],
    ]],
    'ar' => ['$or' => [
        ['status' => ['$in' => ['ar_approved','dr_approved','approved',
                                'drc_office_forwarded','drc_rc_forwarded','drc_forwarded',
                                'sent_back_to_drc_rc','sent_back_to_drc']]],
        ['status' => 'rejected', 'rejectedAtStage' => 'ar'],
    ]],
    'dr' => ['$or' => [
        ['status' => ['$in' => ['approved','dr_approved','drc_office_forwarded',
                                'drc_rc_forwarded','drc_forwarded',
                                'sent_back_to_drc_rc','sent_back_to_drc']]],
        ['status' => 'rejected', 'rejectedAtStage' => 'dr'],
    ]],
    'drc_office' => ['$or' => [
        ['status' => ['$in' => ['drc_office_forwarded','drc_rc_forwarded','drc_forwarded',
                                'approved','sent_back_to_drc_rc','sent_back_to_drc']]],
        ['status' => 'rejected', 'rejectedAtStage' => 'drc_office'],
    ]],
    'drc_rc' => ['$or' => [
        ['status' => ['$in' => ['drc_rc_forwarded','drc_forwarded','approved','sent_back_to_drc_rc']]],
        ['status' => 'rejected', 'rejectedAtStage' => 'drc_rc'],
    ]],
    'drc' => ['$or' => [
        ['status' => ['$in' => ['drc_forwarded','approved','sent_back_to_drc_rc','sent_back_to_drc']]],
        ['status' => 'rejected', 'rejectedAtStage' => 'drc'],
    ]],
    'director' => ['$or' => [
        ['status' => ['$in' => ['approved','sent_back_to_drc']]],
        ['status' => 'rejected', 'rejectedAtStage' => 'director'],
    ]],
    'all' => ['status' => ['$in' => ['approved','rejected']]],
];

// ── Helpers ───────────────────────────────────────────────────────────────────
function safeStr($val): string {
    if ($val === null) return '';
    if ($val instanceof MongoDB\BSON\UTCDateTime) return $val->toDateTime()->format('c');
    if ($val instanceof MongoDB\BSON\ObjectId) return (string)$val;
    if (is_array($val) || $val instanceof MongoDB\Model\BSONDocument || $val instanceof MongoDB\Model\BSONArray) return '';
    return (string)$val;
}
function safeFloat($val): float {
    if ($val === null) return 0.0;
    if (is_array($val) || is_object($val)) return 0.0;
    return (float)(string)$val;
}
function safeBool($val): bool { return (bool)$val; }
function safeId($val): string {
    if ($val instanceof MongoDB\BSON\ObjectId) return (string)$val;
    if (is_array($val) && isset($val['$oid'])) return (string)$val['$oid'];
    return (string)$val;
}
function safeDate($val): string {
    if ($val === null) return '';
    if ($val instanceof MongoDB\BSON\UTCDateTime) return $val->toDateTime()->format('c');
    if (is_array($val)) return '';
    return (string)$val;
}
function safeQuery($val): array {
    if (empty($val)) return [];
    $arr = is_array($val) ? $val : (array)$val;
    return [
        'query'         => (string)($arr['query']          ?? ''),
        'raisedBy'      => (string)($arr['raisedBy']       ?? ''),
        'raisedByLabel' => (string)($arr['raisedByLabel']  ?? ''),
        'raisedAt'      => (string)($arr['raisedAt']       ?? ''),
        'raisedStage'   => (string)($arr['raisedStage']    ?? ''),
        'resolved'      => (bool)($arr['resolved']         ?? false),
        'resolvedAt'    => (string)($arr['resolvedAt']     ?? ''),
        'piResponse'    => (string)($arr['piResponse']     ?? ''),
    ];
}
function safeQueriesArr($val): array {
    if (empty($val)) return [];
    $out = [];
    foreach ($val as $q) {
        $q = is_array($q) ? $q : (array)$q;
        $out[] = [
            'by'         => (string)($q['by']         ?? ''),
            'byLabel'    => (string)($q['byLabel']    ?? ''),
            'to'         => (string)($q['to']         ?? ''),
            'query'      => (string)($q['query']      ?? ''),
            'stage'      => (string)($q['stage']      ?? ''),
            'timestamp'  => (string)($q['timestamp']  ?? ''),
            'resolved'   => (bool)($q['resolved']     ?? false),
            'piResponse' => (string)($q['piResponse'] ?? ''),
        ];
    }
    return $out;
}

$stageLabels = [
    'da' => 'Dealing Assistant', 'ar' => 'Accounts Representative',
    'dr' => 'Deputy Registrar', 'drc_office' => 'DRC Office',
    'drc_rc' => 'DRC (R&C)', 'drc' => 'DRC', 'director' => 'Director',
];

try {
    $db = getMongoDBConnection();

    if ($type === 'pending') {
        if (!isset($pendingFilters[$stage])) {
            echo json_encode(['success' => false, 'message' => "No pending filter for stage: $stage"]);
            exit();
        }
        $filter = $pendingFilters[$stage];
    } else {
        $filterKey = array_key_exists($stage, $completedFilters) ? $stage : 'all';
        $filter    = $completedFilters[$filterKey];
    }

    $cursor  = $db->budget_requests->find($filter, ['sort' => ['createdAt' => -1]]);
    $results = [];

    foreach ($cursor as $doc) {
        // Amount extraction
        $amount = 0.0;
        foreach (['requestedAmount', 'amount', 'bookedAmount'] as $f) {
            if (isset($doc[$f])) {
                $v = safeFloat($doc[$f]);
                if ($v > 0) { $amount = $v; break; }
            }
        }

        // History
        $history = [];
        if (!empty($doc['approvalHistory'])) {
            foreach ($doc['approvalHistory'] as $h) {
                $history[] = [
                    'stage'     => safeStr($h['stage']     ?? null),
                    'action'    => safeStr($h['action']    ?? null),
                    'by'        => safeStr($h['by']        ?? null),
                    'timestamp' => safeStr($h['timestamp'] ?? null),
                    'remarks'   => safeStr($h['remarks']   ?? null),
                ];
            }
        }

        // Rejection details
        $rejectedAtStage     = safeStr($doc['rejectedAtStage'] ?? null);
        $rejectedBy          = safeStr($doc['rejectedBy']      ?? null);
        $rejectedAt          = safeDate($doc['rejectedAt']     ?? null);
        $rejectedAtStageLabel = !empty($rejectedAtStage) ? ($stageLabels[$rejectedAtStage] ?? strtoupper($rejectedAtStage)) : '';

        // Rejection remarks — pull from the stage-specific remarks field
        $rejectionRemarksFieldMap = [
            'da' => 'daRemarks', 'ar' => 'arRemarks', 'dr' => 'drRemarks',
            'drc_office' => 'drcOfficeRemarks', 'drc_rc' => 'drcRcRemarks',
            'drc' => 'drcRemarks', 'director' => 'directorRemarks',
        ];
        $rejectionRemarks = '';
        if (!empty($rejectedAtStage) && isset($rejectionRemarksFieldMap[$rejectedAtStage])) {
            $field = $rejectionRemarksFieldMap[$rejectedAtStage];
            $rejectionRemarks = safeStr($doc[$field] ?? null);
        }

        $results[] = [
            'id'               => safeId($doc['_id']),
            'requestNumber'    => safeStr($doc['requestNumber']    ?? null),
            'gpNumber'         => safeStr($doc['gpNumber']         ?? null),

            // ✅ File number — key field for rejection display
            'fileNumber'       => safeStr($doc['fileNumber']       ?? null),
            'quotationFileName'=> safeStr($doc['quotationFileName'] ?? null),

            'projectId'        => safeStr($doc['projectId']        ?? null),
            'projectTitle'     => safeStr($doc['projectTitle']     ?? null),
            'piName'           => safeStr($doc['piName']           ?? null),
            'piEmail'          => safeStr($doc['piEmail']          ?? null),
            'department'       => safeStr($doc['department']       ?? null),
            'purpose'          => safeStr($doc['purpose']          ?? null),
            'description'      => safeStr($doc['description']      ?? null),
            'material'         => safeStr($doc['material']         ?? null),
            'expenditure'      => safeStr($doc['expenditure']      ?? null),
            'mode'             => safeStr($doc['mode']             ?? null),
            'projectType'      => safeStr($doc['projectType']      ?? null),
            'invoiceNumber'    => safeStr($doc['invoiceNumber']    ?? null),
            'headId'           => safeStr($doc['headId']           ?? null),
            'headName'         => safeStr($doc['headName']         ?? null),
            'headType'         => safeStr($doc['headType']         ?? null),
            'amount'           => $amount,
            'actualExpenditure'=> safeFloat($doc['actualExpenditure'] ?? null),
            'status'           => safeStr($doc['status']           ?? null),
            'previousStatus'   => safeStr($doc['previousStatus']   ?? null),
            'currentStage'     => safeStr($doc['currentStage']     ?? null),
            'chainType'        => safeStr($doc['chainType']        ?? null),
            'createdAt'        => safeDate($doc['createdAt']       ?? null),
            'updatedAt'        => safeDate($doc['updatedAt']       ?? null),
            'daRemarks'        => safeStr($doc['daRemarks']        ?? null),
            'arRemarks'        => safeStr($doc['arRemarks']        ?? null),
            'drRemarks'        => safeStr($doc['drRemarks']        ?? null),
            'drcOfficeRemarks' => safeStr($doc['drcOfficeRemarks'] ?? null),
            'drcRcRemarks'     => safeStr($doc['drcRcRemarks']     ?? null),
            'drcRemarks'       => safeStr($doc['drcRemarks']       ?? null),
            'directorRemarks'  => safeStr($doc['directorRemarks']  ?? null),
            'approvalHistory'  => $history,

            // ✅ REJECTION FIELDS
            'rejectedBy'           => $rejectedBy,
            'rejectedAt'           => $rejectedAt,
            'rejectedAtStage'      => $rejectedAtStage,
            'rejectedAtStageLabel' => $rejectedAtStageLabel,
            'rejectionRemarks'     => $rejectionRemarks,

            // Query fields
            'hasOpenQuery'     => safeBool($doc['hasOpenQuery']    ?? false),
            'latestQuery'      => safeQuery($doc['latestQuery']    ?? null),
            'queries'          => safeQueriesArr($doc['queries']   ?? null),
            'piResponse'       => safeStr($doc['piResponse']       ?? null),
        ];
    }

    echo json_encode(['success' => true, 'data' => $results, 'count' => count($results)]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>