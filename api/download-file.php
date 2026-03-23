<?php
// api/download-file.php
//
// Route A — Quotation (base64 in budget_requests):
//   GET ?requestId=<id>&type=quotation
//
// Route B — Sanction letters (disk via project_files):
//   GET ?id=<fileId>
//   GET ?projectId=<id>
//   GET ?gpNumber=<gp>

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';
$db = getMongoDBConnection();

$requestId = $_GET['requestId'] ?? '';
$type      = $_GET['type']      ?? '';

// ── Route A: Quotation from budget_requests ───────────────────────────────────
if ($requestId && $type === 'quotation') {
    try {
        $req = $db->budget_requests->findOne([
            '_id' => new MongoDB\BSON\ObjectId($requestId)
        ]);

        if (!$req) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Request not found']);
            exit();
        }

        $quotationRaw = (string)($req['quotation'] ?? '');

        if (empty($quotationRaw)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'No quotation file found for this request. Note: only requests submitted after the latest update will have the file stored.']);
            exit();
        }

        // Strip data URL prefix: "data:application/pdf;base64,<data>"
        $base64 = $quotationRaw;
        if (strpos($quotationRaw, 'base64,') !== false) {
            $base64 = explode('base64,', $quotationRaw, 2)[1];
        }
        $base64   = trim(str_replace(["\n", "\r", " "], '', $base64));
        $pdfBytes = base64_decode($base64, true);

        if ($pdfBytes === false || strlen($pdfBytes) === 0) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to decode quotation file']);
            exit();
        }

        // Build a descriptive filename
        $fn  = (string)($req['quotationFileName'] ?? '');
        if (empty($fn)) {
            $gp  = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($req['gpNumber']      ?? 'GP'));
            $fno = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($req['fileNumber']    ?? ''));
            $inv = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($req['invoiceNumber'] ?? ''));
            $fn  = "Quotation_{$gp}";
            if ($fno) $fn .= "_{$fno}";
            if ($inv) $fn .= "_{$inv}";
            $fn .= ".pdf";
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $fn . '"');
        header('Content-Length: ' . strlen($pdfBytes));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $pdfBytes;
        exit();

    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}

// ── Route B: Sanction letters from project_files (original behaviour) ─────────
$filesCollection = $db->project_files;

try {
    $fileId    = $_GET['id']        ?? null;
    $projectId = $_GET['projectId'] ?? null;
    $gpNumber  = $_GET['gpNumber']  ?? null;

    if ($fileId) {
        $file = $filesCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($fileId)]);
    } elseif ($projectId) {
        $file = $filesCollection->findOne(
            ['projectId' => $projectId, 'fileType' => 'sanction_letter'],
            ['sort' => ['uploadedAt' => -1]]
        );
    } elseif ($gpNumber) {
        $file = $filesCollection->findOne(
            ['gpNumber' => $gpNumber, 'fileType' => 'sanction_letter'],
            ['sort' => ['uploadedAt' => -1]]
        );
    } else {
        throw new Exception('Provide requestId+type=quotation, or id/projectId/gpNumber');
    }

    if (!$file) throw new Exception('File not found');

    $filePath = __DIR__ . '/../../' . $file['filePath'];
    if (!file_exists($filePath)) throw new Exception('Physical file not found on server');

    header('Content-Type: ' . $file['mimeType']);
    header('Content-Disposition: attachment; filename="' . $file['fileName'] . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: 0');
    readfile($filePath);
    exit();

} catch (Exception $e) {
    error_log("File Download Error: " . $e->getMessage());
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>