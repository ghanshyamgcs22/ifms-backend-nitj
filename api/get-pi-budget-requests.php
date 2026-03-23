<?php
// api/get-pi-budget-requests.php — WITH QUERY + REJECTION + FILE FIELDS

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
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Only GET method allowed');

    $piEmail  = $_GET['piEmail']   ?? '';
    $withFile = ($_GET['withFile'] ?? '1') !== '0';
    if (empty($piEmail)) throw new Exception('PI email is required');

    $cursor = $db->budget_requests->find(
        ['piEmail' => $piEmail],
        ['sort' => ['createdAt' => -1]]
    );

    $stageLabels = [
        'da' => 'Dealing Assistant', 'ar' => 'Accounts Representative',
        'dr' => 'Deputy Registrar', 'drc_office' => 'DRC Office',
        'drc_rc' => 'DRC (R&C)', 'drc' => 'DRC', 'director' => 'Director',
    ];

    $rejectionRemarksFieldMap = [
        'da' => 'daRemarks', 'ar' => 'arRemarks', 'dr' => 'drRemarks',
        'drc_office' => 'drcOfficeRemarks', 'drc_rc' => 'drcRcRemarks',
        'drc' => 'drcRemarks', 'director' => 'directorRemarks',
    ];

    $requests = [];
    foreach ($cursor as $req) {
        $amount            = floatval($req['requestedAmount'] ?? $req['amount'] ?? 0);
        $actualExpenditure = floatval($req['actualExpenditure'] ?? 0);

        // latestQuery
        $latestQuery = null;
        if (!empty($req['latestQuery'])) {
            $lq = is_array($req['latestQuery']) ? $req['latestQuery'] : (array)$req['latestQuery'];
            $latestQuery = [
                'query'         => (string)($lq['query']          ?? ''),
                'raisedBy'      => (string)($lq['raisedBy']       ?? ''),
                'raisedByLabel' => (string)($lq['raisedByLabel']  ?? ''),
                'raisedAt'      => (string)($lq['raisedAt']       ?? ''),
                'raisedStage'   => (string)($lq['raisedStage']    ?? ''),
                'resolved'      => (bool)($lq['resolved']         ?? false),
                'resolvedAt'    => (string)($lq['resolvedAt']     ?? ''),
                'piResponse'    => (string)($lq['piResponse']     ?? ''),
            ];
        }

        // queries[]
        $queries = [];
        if (!empty($req['queries'])) {
            foreach ($req['queries'] as $q) {
                $q = is_array($q) ? $q : (array)$q;
                $queries[] = [
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
        }

        // approvalHistory
        $history = [];
        if (!empty($req['approvalHistory'])) {
            foreach ($req['approvalHistory'] as $h) {
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

        // hasOpenQuery
        $hasOpenQuery = (bool)($req['hasOpenQuery'] ?? false)
                        || (string)($req['status'] ?? '') === 'query_raised';

        // Rejection details
        $rejectedAtStage      = (string)($req['rejectedAtStage'] ?? '');
        $rejectedBy           = (string)($req['rejectedBy']      ?? '');
        $rejectionRemarks     = '';
        $rejectedAtStageLabel = '';
        if (!empty($rejectedAtStage)) {
            $rejectedAtStageLabel = $stageLabels[$rejectedAtStage] ?? strtoupper($rejectedAtStage);
            if (isset($rejectionRemarksFieldMap[$rejectedAtStage])) {
                $field = $rejectionRemarksFieldMap[$rejectedAtStage];
                $rejectionRemarks = (string)($req[$field] ?? '');
            }
        }

        // Quotation file
        $quotationFile     = '';
        $quotationFileName = (string)($req['quotationFileName'] ?? '');
        if ($withFile && !empty($req['quotation'])) {
            $quotationFile = (string)$req['quotation'];
        }
        if (empty($quotationFileName) && !empty($req['invoiceNumber'])) {
            $quotationFileName = 'Quotation_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$req['invoiceNumber']) . '.pdf';
        } elseif (empty($quotationFileName)) {
            $quotationFileName = 'Quotation.pdf';
        }

        $requests[] = [
            'id'                   => (string)$req['_id'],
            'requestNumber'        => (string)($req['requestNumber']    ?? ''),
            'projectId'            => (string)($req['projectId']        ?? ''),
            'gpNumber'             => (string)($req['gpNumber']         ?? ''),

            // ✅ File number
            'fileNumber'           => (string)($req['fileNumber']       ?? ''),

            'projectTitle'         => (string)($req['projectTitle']     ?? ''),
            'projectType'          => (string)($req['projectType']      ?? ''),
            'piName'               => (string)($req['piName']           ?? ''),
            'piEmail'              => (string)($req['piEmail']          ?? ''),
            'department'           => (string)($req['department']       ?? ''),
            'headId'               => (string)($req['headId']           ?? ''),
            'headName'             => (string)($req['headName']         ?? ''),
            'headType'             => (string)($req['headType']         ?? ''),
            'amount'               => $amount,
            'requestedAmount'      => $amount,
            'actualExpenditure'    => $actualExpenditure,
            'purpose'              => (string)($req['purpose']          ?? ''),
            'description'          => (string)($req['description']      ?? ''),
            'material'             => (string)($req['material']         ?? ''),
            'expenditure'          => (string)($req['expenditure']      ?? ''),
            'mode'                 => (string)($req['mode']             ?? ''),
            'invoiceNumber'        => (string)($req['invoiceNumber']    ?? ''),
            'status'               => (string)($req['status']           ?? 'pending'),
            'previousStatus'       => (string)($req['previousStatus']   ?? ''),
            'currentStage'         => (string)($req['currentStage']     ?? 'da'),
            'daRemarks'            => (string)($req['daRemarks']        ?? ''),
            'arRemarks'            => (string)($req['arRemarks']        ?? ''),
            'drRemarks'            => (string)($req['drRemarks']        ?? ''),
            'drcOfficeRemarks'     => (string)($req['drcOfficeRemarks'] ?? ''),
            'drcRcRemarks'         => (string)($req['drcRcRemarks']     ?? ''),
            'drcRemarks'           => (string)($req['drcRemarks']       ?? ''),
            'directorRemarks'      => (string)($req['directorRemarks']  ?? ''),

            // ✅ Query fields
            'hasOpenQuery'         => $hasOpenQuery,
            'latestQuery'          => $latestQuery,
            'queries'              => $queries,
            'piResponse'           => (string)($req['piResponse']       ?? ''),

            // ✅ Rejection fields
            'rejectedBy'           => $rejectedBy,
            'rejectedAtStage'      => $rejectedAtStage,
            'rejectedAtStageLabel' => $rejectedAtStageLabel,
            'rejectionRemarks'     => $rejectionRemarks,
            'rejectedAt'           => isset($req['rejectedAt'])
                ? $req['rejectedAt']->toDateTime()->format('Y-m-d H:i:s') : null,

            // Quotation file
            'quotationFile'        => $quotationFile,
            'quotationFileName'    => $quotationFileName,

            'approvalHistory'      => $history,
            'createdAt'            => isset($req['createdAt'])
                ? $req['createdAt']->toDateTime()->format('Y-m-d H:i:s') : null,
            'updatedAt'            => isset($req['updatedAt'])
                ? $req['updatedAt']->toDateTime()->format('Y-m-d H:i:s') : null,
        ];
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Budget requests retrieved successfully',
        'data'    => $requests,
        'count'   => count($requests),
    ]);

} catch (Exception $e) {
    ob_end_clean();
    error_log("Get PI Budget Requests Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>