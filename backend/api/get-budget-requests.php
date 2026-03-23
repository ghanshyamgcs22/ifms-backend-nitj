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

    $options = ['sort' => ['createdAt' => -1]];
    $cursor  = $collection->find($filter, $options);

    $result = [];
    foreach ($cursor as $r) {
        // ── FIX: requestedAmount is the canonical field written by create-budget-requests.php
        // Fall back to 'amount' for any legacy documents.
        $amount = floatval($r['requestedAmount'] ?? $r['amount'] ?? 0);

        $result[] = [
            'id'              => (string)($r['_id']),
            'requestNumber'   => $r['requestNumber']   ?? '',
            'projectId'       => $r['projectId']       ?? '',
            'gpNumber'        => $r['gpNumber']        ?? '',
            'projectTitle'    => $r['projectTitle']    ?? '',
            // ── FIX: removed undefined $linkedProject reference; read directly from document
            'piName'          => $r['piName']          ?? '',
            'piEmail'         => $r['piEmail']         ?? '',
            'department'      => $r['department']      ?? '',
            'purpose'         => $r['purpose']         ?? '',
            'description'     => $r['description']     ?? '',
            // ── FIX: correct field name exposed to frontend
            'amount'          => $amount,
            'requestedAmount' => $amount,
            'projectType'     => $r['projectType']     ?? '',
            'invoiceNumber'   => $r['invoiceNumber']   ?? '',
            'headId'          => $r['headId']          ?? '',
            'headName'        => $r['headName']        ?? '',
            'headType'        => $r['headType']        ?? '',
            'status'          => $r['status']          ?? 'pending',
            'currentStage'    => $r['currentStage']    ?? 'da',
            'daRemarks'       => $r['daRemarks']       ?? '',
            'arRemarks'       => $r['arRemarks']       ?? '',
            'drRemarks'       => $r['drRemarks']       ?? '',
            'actualExpenditure' => floatval($r['actualExpenditure'] ?? 0),
            'approvalHistory' => isset($r['approvalHistory'])
                ? array_values(iterator_to_array($r['approvalHistory']))
                : [],
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