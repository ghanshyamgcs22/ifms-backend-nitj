<?php
// api/get-approval-certificate.php
// Returns full data for the "Admn cum Financial Approval Under MEITY Project" certificate.
// ✅ Uses fileNumber stored on the request (updated when PI re-uploads after query).
// ✅ All fields sourced from budget_requests (filled at BookBudget time).
// ✅ Only returns data if status = 'approved'.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';

$requestId = trim($_GET['requestId'] ?? '');
if (!$requestId) {
    echo json_encode(['success' => false, 'message' => 'requestId required']); exit();
}

// ── Number to words ───────────────────────────────────────────────────────────
function numberToWords(float $amount): string {
    $amount = (int)round($amount);
    if ($amount === 0) return 'Zero Only';
    $ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten',
             'Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    $tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];

    function convertChunk(int $n, array $ones, array $tens): string {
        $r = '';
        if ($n >= 100) { $r .= $ones[(int)($n/100)] . ' Hundred '; $n %= 100; }
        if ($n >= 20)  { $r .= $tens[(int)($n/10)]; $n %= 10; if ($n > 0) $r .= '-'; }
        if ($n > 0)    { $r .= $ones[$n]; }
        return trim($r);
    }

    $r = '';
    if ($amount >= 10000000) { $r .= convertChunk((int)($amount/10000000), $ones, $tens) . ' Crore '; $amount %= 10000000; }
    if ($amount >= 100000)   { $r .= convertChunk((int)($amount/100000),   $ones, $tens) . ' Lakh ';  $amount %= 100000; }
    if ($amount >= 1000)     { $r .= convertChunk((int)($amount/1000),     $ones, $tens) . ' Thousand '; $amount %= 1000; }
    if ($amount > 0)         { $r .= convertChunk((int)$amount,            $ones, $tens); }
    return trim($r) . ' Only';
}

// ── Format a UTCDateTime or ISO string to dd.mm.yyyy ─────────────────────────
function fmtDate($val): string {
    if (empty($val)) return '';
    if ($val instanceof MongoDB\BSON\UTCDateTime) {
        return $val->toDateTime()->format('d.m.Y');
    }
    try { return (new DateTime((string)$val))->format('d.m.Y'); }
    catch (Exception $e) { return (string)$val; }
}

try {
    $db  = getMongoDBConnection();
    $req = $db->budget_requests->findOne(['_id' => new MongoDB\BSON\ObjectId($requestId)]);

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Request not found']); exit();
    }
    if ((string)($req['status'] ?? '') !== 'approved') {
        echo json_encode(['success' => false, 'message' => 'Certificate only available for approved requests']); exit();
    }

    // ── Fetch linked project ──────────────────────────────────────────────────
    $project = null;
    if (!empty($req['projectId'])) {
        try {
            $project = $db->projects->findOne([
                '_id' => new MongoDB\BSON\ObjectId((string)$req['projectId'])
            ]);
        } catch (Exception $e) { /* project fetch optional */ }
    }

    // ── Find approval date from history ──────────────────────────────────────
    // The "final" approval is director (>25k chain) or dr (≤25k chain).
    $approvedDate  = '';
    $approvedByRole = '';
    $history = isset($req['approvalHistory']) ? iterator_to_array($req['approvalHistory']) : [];
    foreach (array_reverse($history) as $h) {
        $action = (string)($h['action'] ?? '');
        $stage  = (string)($h['stage']  ?? '');
        if ($action === 'approved' && in_array($stage, ['director', 'dr'])) {
            $approvedDate   = fmtDate($h['timestamp'] ?? '');
            $approvedByRole = $stage;
            break;
        }
    }
    // Fallback: updatedAt
    if (!$approvedDate && !empty($req['updatedAt'])) {
        $approvedDate = fmtDate($req['updatedAt']);
    }

    // ── Core amounts ─────────────────────────────────────────────────────────
    $amount = floatval($req['requestedAmount'] ?? $req['amount'] ?? 0);

    // ── fileNumber: ALWAYS use what's stored on the request ──────────────────
    // This is updated by resolve-query.php when PI re-uploads with a new file number.
    $fileNumber = (string)($req['fileNumber'] ?? '');

    // ── Project end date ─────────────────────────────────────────────────────
    $projectEndDate = '';
    if ($project) {
        foreach (['projectEndDate','endDate','completionDate'] as $f) {
            if (!empty($project[$f])) { $projectEndDate = fmtDate($project[$f]); break; }
        }
    }

    // ── Mode of procurement fallback ─────────────────────────────────────────
    $mode = (string)($req['mode'] ?? '');
    if (!$mode) {
        $mode = 'Through Direct Purchase on GeM portal under GFR 2017 rule (149-I).';
    }

    // ── Availability of funds fallback ───────────────────────────────────────
    $expenditure = (string)($req['expenditure'] ?? '');
    if (!$expenditure) {
        $gpNum   = (string)($req['gpNumber'] ?? '');
        $headN   = (string)($req['headName'] ?? '');
        $headT   = (string)($req['headType'] ?? '');
        $expenditure = "Yes, as per IFMS Budget-ID/{$gpNum} dated {$approvedDate}"
                     . ($headN ? " under Head \"{$headN}" . ($headT ? " ({$headT})" : '') . '"' : '');
    }

    echo json_encode([
        'success' => true,
        'data'    => [
            // ── Identifiers ─────────────────────────────────────────────────
            'requestId'      => (string)$req['_id'],
            'requestNumber'  => (string)($req['requestNumber'] ?? ''),

            // ✅ Always the most up-to-date file number (updated on re-upload)
            'fileNumber'     => $fileNumber,
            'approvedDate'   => $approvedDate,

            // ── Row 1: Project ───────────────────────────────────────────────
            'projectTitle'   => (string)($req['projectTitle'] ?? ''),
            'projectType'    => (string)($req['projectType']  ?? ''),
            'gpNumber'       => (string)($req['gpNumber']     ?? ''),

            // ── Row 2: PI & Department ───────────────────────────────────────
            'piName'         => (string)($req['piName']       ?? ''),
            'piEmail'        => (string)($req['piEmail']      ?? ''),
            'department'     => (string)($req['department']   ?? ''),

            // ── Row 3: Completion date ───────────────────────────────────────
            'projectEndDate' => $projectEndDate,

            // ── Row 4: Total project cost ────────────────────────────────────
            'totalSanctionedAmount' => $project ? floatval($project['totalSanctionedAmount'] ?? 0) : 0,
            'totalReleasedAmount'   => $project ? floatval($project['totalReleasedAmount']   ?? 0) : 0,

            // ── Row 5: Material (filled at BookBudget) ───────────────────────
            'material'       => (string)($req['material']     ?? ''),
            'headName'       => (string)($req['headName']     ?? ''),
            'headType'       => (string)($req['headType']     ?? ''),

            // ── Row 6: Amount (filled at BookBudget) ─────────────────────────
            'amount'         => $amount,
            'amountWords'    => numberToWords($amount),

            // ── Row 7: Availability of funds (filled at BookBudget) ──────────
            'expenditure'    => $expenditure,

            // ── Row 8: Mode of procurement (filled at BookBudget) ────────────
            'mode'           => $mode,

            // ── Row 9: Special remarks ───────────────────────────────────────
            'purpose'        => (string)($req['purpose']      ?? ''),
            'description'    => (string)($req['description']  ?? ''),

            // ── Invoice / quotation ──────────────────────────────────────────
            'invoiceNumber'  => (string)($req['invoiceNumber'] ?? ''),

            // ── Reviewer remarks (for special remarks section) ───────────────
            'daRemarks'      => (string)($req['daRemarks']     ?? ''),
            'arRemarks'      => (string)($req['arRemarks']     ?? ''),
            'drRemarks'      => (string)($req['drRemarks']     ?? ''),
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>