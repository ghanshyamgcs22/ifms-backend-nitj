<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

require_once __DIR__ . '/../config/database.php';

$db = getMongoDBConnection();
$projectsCollection   = $db->projects;
$extensionsCollection = $db->project_extensions;

try {
    // ── 1. Validate required fields ──────────────────────────────────────────
    $projectId       = $_POST['projectId']       ?? null;
    $gpNumber        = $_POST['gpNumber']        ?? null;
    $originalEndDate = $_POST['originalEndDate'] ?? null;
    $extendedEndDate = $_POST['extendedEndDate'] ?? null;
    $additionalYears = $_POST['additionalYears'] ?? '0';
    $remarks         = $_POST['remarks']         ?? '';
    $extendedBy      = $_POST['extendedBy']      ?? 'admin_user';

    if (!$projectId || !$gpNumber || !$extendedEndDate) {
        throw new Exception('projectId, gpNumber and extendedEndDate are required');
    }

    // ── 2. Handle PDF upload (optional) ──────────────────────────────────────
    $pdfFilePath = null;
    $pdfFileSize = null;
    $pdfOrigName = null;

    if (isset($_FILES['extensionPdf']) && $_FILES['extensionPdf']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['extensionPdf'];

        if ($file['type'] !== 'application/pdf') {
            throw new Exception('Only PDF files are allowed for the extension letter');
        }
        if ($file['size'] > 10 * 1024 * 1024) {
            throw new Exception('Extension PDF must be smaller than 10 MB');
        }

        $uploadBase = __DIR__ . '/../../uploads/extensions/';
        $uploadDir  = $uploadBase . $gpNumber . '/';

        if (!file_exists($uploadBase)) mkdir($uploadBase, 0777, true);
        if (!file_exists($uploadDir))  mkdir($uploadDir,  0777, true);

        $storedName   = 'extension_' . time() . '_' . uniqid() . '.pdf';
        $physicalPath = $uploadDir . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $physicalPath)) {
            throw new Exception('Failed to save the uploaded PDF to disk');
        }

        $pdfFilePath = '/uploads/extensions/' . $gpNumber . '/' . $storedName;
        $pdfFileSize = $file['size'];
        $pdfOrigName = $file['name'];
    }

    // ── 3. Build extension record ─────────────────────────────────────────────
    $extensionDoc = [
        'projectId'                => $projectId,
        'gpNumber'                 => $gpNumber,
        'originalEndDate'          => $originalEndDate,
        'extendedEndDate'          => $extendedEndDate,
        'additionalYears'          => (float) $additionalYears,
        'remarks'                  => $remarks,
        'extendedBy'               => $extendedBy,
        'extendedAt'               => new MongoDB\BSON\UTCDateTime(),
        'createdAt'                => new MongoDB\BSON\UTCDateTime(),
        'extensionPdfPath'         => $pdfFilePath,
        'extensionPdfOriginalName' => $pdfOrigName,
        'extensionPdfSize'         => $pdfFileSize,
        'extensionPdfUploadedAt'   => $pdfFilePath ? new MongoDB\BSON\UTCDateTime() : null,
    ];

    // ── 4. Insert into project_extensions ────────────────────────────────────
    $insertResult = $extensionsCollection->insertOne($extensionDoc);
    $extensionId  = (string) $insertResult->getInsertedId();

    // ── 5. Update the project document ───────────────────────────────────────
    // CRITICAL FIX: save projectEndDate as UTCDateTime, NOT a plain string.
    // Project::formatDocument() calls ->toDateTime() on this field — if it's
    // a string that call throws "Call to member function toDateTime() on string"
    // which breaks the entire GET /api/projects.php response.
    $extendedEndUTC = new MongoDB\BSON\UTCDateTime(
        (new DateTime($extendedEndDate))->getTimestamp() * 1000
    );

    $updateFields = [
        'projectEndDate'  => $extendedEndUTC,   // ← UTCDateTime object, not a string
        'hasExtension'    => true,
        'lastExtensionId' => $extensionId,
        'lastExtendedAt'  => new MongoDB\BSON\UTCDateTime(),
        'updatedAt'       => new MongoDB\BSON\UTCDateTime(),
    ];

    if ($pdfFilePath) {
        $updateFields['extensionLetterFile']         = $pdfFilePath;
        $updateFields['extensionLetterFileName']     = $pdfOrigName;
        $updateFields['extensionLetterUploadedAt']   = new MongoDB\BSON\UTCDateTime();
    }

    $projectsCollection->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($projectId)],
        ['$set' => $updateFields]
    );

    // ── 6. Respond ────────────────────────────────────────────────────────────
    header('Content-Type: application/json');
    echo json_encode([
        'success'     => true,
        'message'     => 'Project extended successfully',
        'extensionId' => $extensionId,
        'pdfUploaded' => $pdfFilePath !== null,
        'pdfPath'     => $pdfFilePath,
    ]);

} catch (Exception $e) {
    error_log('Extend Project Error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>