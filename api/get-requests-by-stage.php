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
        $result[] = [
            'id'              => (string)($r['_id']),
            'gpNumber'        => $r['gpNumber']        ?? '',
            'projectTitle'    => $r['projectTitle']    ?? '',
            'piName'       => $linkedProject['piName'] ?? $r['piName'] ?? '',  // prefer project's PI
        'piEmail'      => $linkedProject['piEmail'] ?? $r['piEmail'] ?? '',
            'department'      => $r['department']      ?? '',
            'purpose'         => $r['purpose']         ?? '',
            'description'     => $r['description']     ?? '',
            'amount'          => (float)($r['requestedAmount'] ?? $r['amount'] ?? 0),
            'projectType'     => $r['projectType']     ?? '',
            'invoiceNumber'   => $r['invoiceNumber']   ?? '',
            'status'          => $r['status']          ?? 'pending',
            'currentStage'    => $r['currentStage']    ?? 'da',
            'createdAt'       => isset($r['createdAt']) ? $r['createdAt']->toDateTime()->format('Y-m-d H:i:s') : '',
            'updatedAt'       => isset($r['updatedAt']) ? $r['updatedAt']->toDateTime()->format('Y-m-d H:i:s') : '',
            'daRemarks'       => $r['daRemarks']       ?? '',
            'arRemarks'       => $r['arRemarks']       ?? '',
            'drRemarks'       => $r['drRemarks']       ?? '',
            'headId'          => $r['headId']          ?? '',
            'headName'        => $r['headName']        ?? '',
            'headType'        => $r['headType']        ?? '',
            'requestNumber'   => $r['requestNumber']   ?? '',
            'projectId'       => $r['projectId']       ?? '',
            'approvalHistory' => isset($r['approvalHistory']) ? iterator_to_array($r['approvalHistory']) : [],
        ];
    }

    echo json_encode(['success' => true, 'data' => $result, 'count' => count($result)]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>