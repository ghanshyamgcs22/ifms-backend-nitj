<?php
// api/resolve-query.php
// ✅ PI can now update ALL purchase fields:
//    purpose, description, invoiceNumber, material, mode, piResponse
// ✅ expenditure is NOT updated by PI — it stays as set by AR/DR
// ✅ fileNumber is ALWAYS kept as the EXISTING one — never changed
// ✅ Restores previousStatus so request goes back to the correct reviewer stage

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']); exit();
}

$requestId     = trim($input['requestId']     ?? '');
$piEmail       = trim($input['piEmail']       ?? '');
$piResponse    = trim($input['piResponse']    ?? '');

// ✅ All PI-editable purchase fields
$purpose       = trim($input['purpose']       ?? '');
$description   = trim($input['description']   ?? '');
$invoiceNumber = trim($input['invoiceNumber'] ?? '');
$material      = trim($input['material']      ?? ''); // ✅ PI can correct material/qty
$mode          = trim($input['mode']          ?? ''); // ✅ PI can correct mode of procurement

// ✅ expenditure is intentionally NOT accepted from PI — owned by AR/DR

// New quotation file (optional)
$newQuotation     = $input['newQuotation']     ?? '';
$newQuotationName = trim($input['newQuotationName'] ?? '');

if (!$requestId)  { echo json_encode(['success' => false, 'message' => 'requestId required']);  exit(); }
if (!$piResponse) { echo json_encode(['success' => false, 'message' => 'piResponse required']); exit(); }

try {
    $db  = getMongoDBConnection();
    $req = $db->budget_requests->findOne(
        ['_id' => new \MongoDB\BSON\ObjectId($requestId)],
        ['projection' => ['quotation' => 0]]
    );

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Request not found']); exit();
    }
    if (!$req['hasOpenQuery'] && $req['status'] !== 'query_raised') {
        echo json_encode(['success' => false, 'message' => 'No open query on this request']); exit();
    }
    if ($piEmail && (string)($req['piEmail'] ?? '') !== $piEmail) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit();
    }

    // ── Always use the EXISTING file number from DB — never change it ─────
    $existingFileNumber = (string)($req['fileNumber'] ?? '');

    $now = new \MongoDB\BSON\UTCDateTime();

    // ── Restore status ────────────────────────────────────────────────────
    $restoredStatus = !empty($req['previousStatus']) ? (string)$req['previousStatus'] : 'pending';

    // ── Build approval history entry ──────────────────────────────────────
    $history = isset($req['approvalHistory']) ? iterator_to_array($req['approvalHistory']) : [];
    $history[] = [
        'stage'     => (string)($req['currentStage'] ?? 'pi'),
        'action'    => 'query_resolved',
        'by'        => $piEmail ?: 'pi',
        'timestamp' => date('c'),
        'remarks'   => $piResponse,
    ];

    // ── Mark latestQuery resolved ─────────────────────────────────────────
    $latestQuery = isset($req['latestQuery']) ? (array)$req['latestQuery'] : [];
    $latestQuery['resolved']   = true;
    $latestQuery['resolvedAt'] = date('c');
    $latestQuery['piResponse'] = $piResponse;

    // ── Mark all open queries resolved ────────────────────────────────────
    $queries = [];
    if (!empty($req['queries'])) {
        foreach ($req['queries'] as $q) {
            $q = is_array($q) ? $q : (array)$q;
            if (!($q['resolved'] ?? false)) {
                $q['resolved']   = true;
                $q['resolvedAt'] = date('c');
                $q['piResponse'] = $piResponse;
            }
            $queries[] = $q;
        }
    }

    // ── Base $set fields ──────────────────────────────────────────────────
    $setFields = [
        'status'          => $restoredStatus,
        'previousStatus'  => '',
        'hasOpenQuery'    => false,
        'latestQuery'     => $latestQuery,
        'queries'         => $queries,
        'approvalHistory' => $history,
        'piResponse'      => $piResponse,
        'updatedAt'       => $now,
    ];

    // ── Only update fields that were actually sent (non-empty) ────────────
    if (!empty($purpose))       $setFields['purpose']       = htmlspecialchars(strip_tags($purpose));
    if (!empty($description))   $setFields['description']   = htmlspecialchars(strip_tags($description));
    if (!empty($invoiceNumber)) $setFields['invoiceNumber']  = htmlspecialchars(strip_tags($invoiceNumber));
    if (!empty($material))      $setFields['material']       = htmlspecialchars(strip_tags($material));
    if (!empty($mode))          $setFields['mode']           = htmlspecialchars(strip_tags($mode));

    // ── NEW QUOTATION: update file content only, keep fileNumber unchanged ─
    if (!empty($newQuotation)) {
        $setFields['quotation'] = $newQuotation;

        // Build a descriptive filename using existing file number
        $safeGp  = preg_replace('/[^a-zA-Z0-9_\-\/]/', '_', $req['gpNumber'] ?? '');
        $safeFN  = preg_replace('/[^a-zA-Z0-9_\-\/]/', '_', $existingFileNumber);
        $safeInv = preg_replace('/[^a-zA-Z0-9_-]/', '_', $invoiceNumber ?: (string)($req['invoiceNumber'] ?? ''));
        $generatedName = "Quotation_{$safeGp}_{$safeFN}";
        if ($safeInv) $generatedName .= "_{$safeInv}";
        $generatedName .= ".pdf";

        $setFields['quotationFileName'] = $newQuotationName ?: $generatedName;
        // ✅ fileNumber is NOT in $setFields — DB value is preserved automatically
    }

    $db->budget_requests->updateOne(
        ['_id' => new \MongoDB\BSON\ObjectId($requestId)],
        ['$set' => $setFields]
    );

    $message = !empty($newQuotation)
        ? "Query resolved. New quotation uploaded. File number {$existingFileNumber} retained. Returned to reviewer."
        : "Query resolved. Response submitted. Request returned to reviewer.";

    echo json_encode([
        'success' => true,
        'message' => $message,
        'data'    => [
            'status'         => $restoredStatus,
            'fileNumber'     => $existingFileNumber,
            'newFileUploaded'=> !empty($newQuotation),
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>