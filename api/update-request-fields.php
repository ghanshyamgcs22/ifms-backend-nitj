<?php
// api/update-request-fields.php
// ✅ Point 7(a) material    — NEVER editable by any reviewer (PI-only field)
// ✅ Point 7(b) expenditure — AR and DR only
// ✅ Point 8    mode        — DRC R&C and DRC only

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
require_once __DIR__ . '/../config/database.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit();
}
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['success' => false, 'message' => 'Invalid JSON']); exit(); }

$requestId = trim($input['requestId'] ?? '');
$stage     = trim($input['stage']     ?? '');

if (!$requestId || !$stage) {
    echo json_encode(['success' => false, 'message' => 'requestId and stage required']); exit();
}

// ✅ material (7a) is intentionally absent — no stage can edit it via this endpoint
$point7bStages = ['ar', 'dr'];           // can edit expenditure (7b) only
$point8Stages  = ['drc_rc', 'drc'];      // can edit mode (8) only
$allAllowed    = array_merge($point7bStages, $point8Stages);

if (!in_array($stage, $allAllowed)) {
    echo json_encode([
        'success' => false,
        'message' => "Stage '{$stage}' cannot edit these fields. " .
                     "Point 7(b) expenditure: AR/DR only. Point 8 mode: DRC R&C/DRC only.",
    ]); exit();
}

// Reject any attempt to edit material (7a) regardless of stage
if (array_key_exists('material', $input)) {
    echo json_encode([
        'success' => false,
        'message' => "Point 7(a) — material is set by the PI and cannot be edited by any reviewer.",
    ]); exit();
}

try {
    $db  = getMongoDBConnection();
    $req = $db->budget_requests->findOne(
        ['_id' => new MongoDB\BSON\ObjectId($requestId)],
        ['projection' => ['quotation' => 0]]
    );
    if (!$req) { echo json_encode(['success' => false, 'message' => 'Request not found']); exit(); }

    $set     = ['updatedAt' => new MongoDB\BSON\UTCDateTime()];
    $updated = [];

    // ── Point 7(b): expenditure — AR and DR only ─────────────────────────────
    if (in_array($stage, $point7bStages)) {
        if (array_key_exists('expenditure', $input)) {
            $set['expenditure'] = htmlspecialchars(strip_tags(trim($input['expenditure'])));
            $updated[]          = 'expenditure';
        }
        // Guard: DRC fields not allowed here
        if (array_key_exists('mode', $input)) {
            echo json_encode([
                'success' => false,
                'message' => "Stage '{$stage}' cannot edit Point 8 (mode). Only DRC R&C and DRC can.",
            ]); exit();
        }
    }

    // ── Point 8: mode — DRC R&C and DRC only ─────────────────────────────────
    if (in_array($stage, $point8Stages)) {
        if (array_key_exists('mode', $input)) {
            $set['mode']   = htmlspecialchars(strip_tags(trim($input['mode'])));
            $updated[]     = 'mode';
        }
        // Guard: AR/DR fields not allowed here
        if (array_key_exists('expenditure', $input)) {
            echo json_encode([
                'success' => false,
                'message' => "Stage '{$stage}' cannot edit Point 7(b) (expenditure). Only AR and DR can.",
            ]); exit();
        }
    }

    if (empty($updated)) {
        echo json_encode([
            'success' => false,
            'message' => 'No valid fields provided. ' .
                         "AR/DR: send 'expenditure'. DRC R&C/DRC: send 'mode'. " .
                         "Note: 'material' (7a) is never editable by reviewers.",
        ]); exit();
    }

    $db->budget_requests->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($requestId)],
        ['$set' => $set]
    );

    $responseData = [];
    foreach ($updated as $field) $responseData[$field] = $set[$field];

    echo json_encode([
        'success'       => true,
        'message'       => 'Saved successfully.',
        'updatedFields' => $updated,
        'data'          => $responseData,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>