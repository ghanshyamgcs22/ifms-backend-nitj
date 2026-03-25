<?php
// api/sendback-request.php
// Sends a request back to the PREVIOUS stage for re-evaluation.
//
// Chain:  DA → AR → DR → DRC Office → DR (R&C) → DRC → Director
// Sendback map (who sends back → where it goes):
//   AR         → sent_back_to_da       (currentStage = da)
//   DR         → sent_back_to_ar       (currentStage = ar)
//   DRC Office → sent_back_to_dr       (currentStage = dr)
//   DR (R&C)  → sent_back_to_drc_office (currentStage = drc_office)
//   DRC        → sent_back_to_drc_rc   (currentStage = drc_rc)
//   Director   → sent_back_to_drc      (currentStage = drc)
// No special imports needed, using absolute namespaces for MongoDB BSON classes

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit();
}

$input      = json_decode(file_get_contents('php://input'), true);
$requestId  = $input['requestId']  ?? '';
$remarks    = trim($input['remarks'] ?? '');
$sentBackBy = $input['sentBackBy'] ?? ''; // e.g. "AR", "DR", "DRC_OFFICE", "DRC_RC", "DRC", "DIRECTOR"

// ── Sendback map ──────────────────────────────────────────────────────────────
// Key   = currentStage of the request right now (who is acting)
// Value = [new status, new currentStage, history action label]
const SENDBACK_MAP = [
    'ar'         => ['status' => 'sent_back_to_da',         'nextStage' => 'da',         'label' => 'Sent back to DA by AR'],
    'dr'         => ['status' => 'sent_back_to_ar',         'nextStage' => 'ar',         'label' => 'Sent back to AR by DR'],
    'drc_office' => ['status' => 'sent_back_to_dr',         'nextStage' => 'dr',         'label' => 'Sent back to DR by DRC Office'],
    'drc_rc'     => ['status' => 'sent_back_to_drc_office', 'nextStage' => 'drc_office', 'label' => 'Sent back to DRC Office by DR (R&C)'],
    'drc'        => ['status' => 'sent_back_to_drc_rc',     'nextStage' => 'drc_rc',     'label' => 'Sent back to DR (R&C) by DRC'],
    'director'   => ['status' => 'sent_back_to_drc',        'nextStage' => 'drc',        'label' => 'Sent back to DRC by Director'],
];

if (!$requestId) {
    echo json_encode(['success' => false, 'message' => 'requestId is required']); exit();
}
if (!$remarks) {
    echo json_encode(['success' => false, 'message' => 'Remarks are required for sending back']); exit();
}

try {
    $db  = getMongoDBConnection();
    $req = $db->budget_requests->findOne(
        ['_id' => new ObjectId($requestId)],
        ['projection' => ['quotation' => 0]]
    );

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Request not found']); exit();
    }

    $currentStage = (string)($req['currentStage'] ?? '');

    if (!isset(SENDBACK_MAP[$currentStage])) {
        echo json_encode([
            'success' => false,
            'message' => "Sendback not allowed from stage: '{$currentStage}'. DA is the first stage and cannot send back.",
        ]); exit();
    }

    $map       = SENDBACK_MAP[$currentStage];
    $newStatus = $map['status'];
    $nextStage = $map['nextStage'];
    $label     = $map['label'];

    // ── Validate the request is actually actionable at this stage ─────────────
    $validStatuses = [
        'ar'         => ['da_approved',          'sent_back_to_ar'],
        'dr'         => ['ar_approved',           'sent_back_to_dr'],
        'drc_office' => ['dr_approved',           'sent_back_to_drc_office'],
        'drc_rc'     => ['drc_office_forwarded',  'sent_back_to_drc_rc'],
        'drc'        => ['drc_rc_forwarded',      'sent_back_to_drc'],
        'director'   => ['drc_forwarded',         'sent_back_to_director'],
    ];

    $currentStatus = (string)($req['status'] ?? '');
    if (isset($validStatuses[$currentStage]) && !in_array($currentStatus, $validStatuses[$currentStage])) {
        echo json_encode([
            'success' => false,
            'message' => "Cannot send back. Current status '{$currentStatus}' is not valid for sendback at stage '{$currentStage}'.",
        ]); exit();
    }

    $now     = new \MongoDB\BSON\UTCDateTime();
    $history = isset($req['approvalHistory']) ? iterator_to_array($req['approvalHistory']) : [];
    $history[] = [
        'stage'     => $currentStage,
        'action'    => 'sent_back',
        'by'        => $sentBackBy ?: strtoupper($currentStage),
        'timestamp' => date('c'),
        'remarks'   => $remarks,
        'label'     => $label,
    ];

    // Stage-specific remarks field
    $remarksFieldMap = [
        'ar'         => 'arRemarks',
        'dr'         => 'drRemarks',
        'drc_office' => 'drcOfficeRemarks',
        'drc_rc'     => 'drcRcRemarks',
        'drc'        => 'drcRemarks',
        'director'   => 'directorRemarks',
    ];
    $remarksField = $remarksFieldMap[$currentStage] ?? ($currentStage . 'Remarks');

    $db->budget_requests->updateOne(
        ['_id' => new \MongoDB\BSON\ObjectId($requestId)],
        ['$set' => [
            'status'          => $newStatus,
            'previousStatus'  => $currentStatus,
            'currentStage'    => $nextStage,
            $remarksField     => $remarks,
            'sentBackBy'      => $sentBackBy ?: strtoupper($currentStage),
            'sentBackAt'      => $now,
            'approvalHistory' => $history,
            'updatedAt'       => $now,
        ]]
    );

    echo json_encode([
        'success' => true,
        'message' => $label,
        'data'    => [
            'status'       => $newStatus,
            'currentStage' => $nextStage,
            'label'        => $label,
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>