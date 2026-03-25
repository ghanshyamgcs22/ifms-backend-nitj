<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit();
}

// ✅ INPUT FIELDS
$projectId    = $input['projectId'] ?? '';
$gpNumber     = $input['gpNumber'] ?? '';
$fileNumber   = $input['fileNumber'] ?? '';
$projectTitle = $input['projectTitle'] ?? '';
$projectType  = $input['projectType'] ?? '';
$piName       = $input['piName'] ?? '';
$piEmail      = $input['piEmail'] ?? '';
$department   = $input['department'] ?? '';
$headId       = $input['headId'] ?? '';
$headName     = $input['headName'] ?? '';
$headType     = $input['headType'] ?? '';
$amount       = floatval($input['amount'] ?? 0);
$purpose      = trim($input['purpose'] ?? '');
$description  = trim($input['description'] ?? '');
$material     = trim($input['material'] ?? '');
$mode         = trim($input['mode'] ?? '');
$projectCompletionDate = $input['projectEndDate'] ?? '';
$invoiceNumber= trim($input['invoiceNumber'] ?? '');

// FILE
$quotation     = $input['quotation'] ?? '';
$quotationName = $input['quotationName'] ?? '';

// ✅ VALIDATION
if (!$projectId || !$gpNumber || !$piEmail || !$headId || $amount <= 0 || !$purpose) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

try {
    $db  = getMongoDBConnection();
    $now = new MongoDB\BSON\UTCDateTime();

    // GENERATE REQUEST NUMBER
    $count = $db->budget_requests->countDocuments([]);
    $requestNumber = 'BR/' . date('Y') . '/' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);

    // ✅ INSERT DOCUMENT
    $requestDoc = [
        'requestNumber' => $requestNumber,
        'projectId' => $projectId,
        'gpNumber' => $gpNumber,
        'fileNumber' => $fileNumber,
        'projectTitle' => $projectTitle,
        'projectType' => $projectType,
        'piName' => $piName,
        'piEmail' => $piEmail,
        'department' => $department,
        'headId' => $headId,
        'headName' => $headName,
        'headType' => $headType,

        'requestedAmount' => $amount,
        'purpose' => $purpose,
        'description' => $description,

        // ✅ FIXED FIELDS
        'material' => $material,
        'mode' => $mode,
        'projectCompletionDate' => $projectCompletionDate,

        'invoiceNumber' => $invoiceNumber,

        'quotation' => $quotation,
        'quotationFileName' => $quotationName,

        'status' => 'pending',
        'currentStage' => 'da',

        'createdAt' => $now,
        'updatedAt' => $now,
    ];

    $result = $db->budget_requests->insertOne($requestDoc);

    echo json_encode([
        'success' => true,
        'message' => 'Created successfully',
        'id' => (string)$result->getInsertedId()
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>