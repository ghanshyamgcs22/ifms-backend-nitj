<?php
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
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Only GET method is allowed');

    $piEmail = $_GET['piEmail'] ?? '';
    if (empty($piEmail)) throw new Exception('PI email is required');

    $requestsCursor = $db->budget_requests->find(
        ['piEmail' => $piEmail],
        ['sort' => ['createdAt' => -1]]
    );

    $requests = [];
    foreach ($requestsCursor as $request) {
        // ── requestedAmount is the canonical field written by create-budget-requests.php
        // Expose it as BOTH 'amount' (what frontend reads) and 'requestedAmount' for clarity.
        $amount = floatval($request['requestedAmount'] ?? $request['amount'] ?? 0);

        // ── actualExpenditure is filled per-request by the DA after full approval.
        // It starts as 0 and gets set by update-actual-expenditure.php.
        $actualExpenditure = floatval($request['actualExpenditure'] ?? 0);

        $requests[] = [
            'id'               => (string)$request['_id'],
            'requestNumber'    => $request['requestNumber']    ?? '',
            'projectId'        => $request['projectId']        ?? '',
            'gpNumber'         => $request['gpNumber']         ?? '',
            'projectTitle'     => $request['projectTitle']     ?? '',
            'projectType'      => $request['projectType']      ?? '',
            'piName'           => $request['piName']           ?? '',
            'piEmail'          => $request['piEmail']          ?? '',
            'headId'           => $request['headId']           ?? '',
            'headName'         => $request['headName']         ?? '',
            'headType'         => $request['headType']         ?? '',
            // ── The booked amount for this specific request ──
            'amount'           => $amount,           // what frontend reads
            'requestedAmount'  => $amount,           // alias
            // ── Actual expenditure for THIS request (filled by DA per request) ──
            'actualExpenditure'=> $actualExpenditure,
            'purpose'          => $request['purpose']          ?? '',
            'description'      => $request['description']      ?? '',
            'invoiceNumber'    => $request['invoiceNumber']    ?? '',
            'status'           => $request['status']           ?? 'pending',
            'currentStage'     => $request['currentStage']     ?? 'da',
            'daRemarks'        => $request['daRemarks']        ?? '',
            'arRemarks'        => $request['arRemarks']        ?? '',
            'drRemarks'        => $request['drRemarks']        ?? '',
            'approvalHistory'  => isset($request['approvalHistory'])
                ? array_values(iterator_to_array($request['approvalHistory']))
                : [],
            'createdAt'        => isset($request['createdAt'])
                ? $request['createdAt']->toDateTime()->format('Y-m-d H:i:s') : null,
            'updatedAt'        => isset($request['updatedAt'])
                ? $request['updatedAt']->toDateTime()->format('Y-m-d H:i:s') : null,
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