<?php
// api/get-next-file-num.php
// Generates the NEXT file number for a GP Number.
//
// Logic:
//   - Find the highest FILE-NNN index already used for this gpNumber
//   - Return that index + 1
//
// This means:
//   - First BookBudget for GP/25-26/014  → FILE-001
//   - Query raised, PI re-uploads        → FILE-002
//   - Another query, PI re-uploads again → FILE-003
//   - Never duplicates, never skips.
//
// Format: {gpNumber}/FILE-{NNN}
// Example: GP/25-26/014/FILE-002

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';

$gpNumber = trim($_GET['gpNumber'] ?? '');

if (!$gpNumber) {
    echo json_encode(['success' => false, 'message' => 'gpNumber is required']);
    exit();
}

try {
    $db = getMongoDBConnection();

    // ── Find all requests for this GP that have a fileNumber ─────────────────
    // Extract the numeric suffix and find the highest one used so far.
    $cursor = $db->budget_requests->find(
        [
            'gpNumber'   => $gpNumber,
            'fileNumber' => ['$exists' => true, '$ne' => ''],
        ],
        ['projection' => ['fileNumber' => 1]]
    );

    $maxIndex = 0;
    foreach ($cursor as $doc) {
        $fn = (string)($doc['fileNumber'] ?? '');
        // Extract trailing number from FILE-NNN pattern
        if (preg_match('/FILE-(\d+)$/i', $fn, $m)) {
            $idx = (int)$m[1];
            if ($idx > $maxIndex) $maxIndex = $idx;
        }
    }

    // Next index is always max + 1
    $nextIndex  = $maxIndex + 1;
    $fileNumber = rtrim($gpNumber, '/') . '/FILE-' . str_pad($nextIndex, 3, '0', STR_PAD_LEFT);

    echo json_encode([
        'success'    => true,
        'fileNumber' => $fileNumber,
        'index'      => $nextIndex,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>