<?php
$_GET['type'] = 'all';
ob_start();
require_once __DIR__ . '/api/get-budget-requests.php';
$output = ob_get_clean();

// Remove non-JSON parts (like MongoDB connection success message)
$jsonStart = strpos($output, '{');
if ($jsonStart !== false) {
    $output = substr($output, $jsonStart);
}

$data = json_decode($output, true);
if (!$data || !isset($data['data'])) {
    echo "Failed to decode API output.\n";
    echo "Raw output: " . $output . "\n";
    exit;
}

foreach ($data['data'] as $r) {
    if ($r['requestNumber'] === 'BR/2026/0043') {
        echo "Found BR/2026/0043:\n";
        echo "  id: " . $r['id'] . "\n";
        echo "  fileNumber: " . ($r['fileNumber'] ?? 'MISSING') . "\n";
        echo "  material: " . ($r['material'] ?? 'MISSING') . "\n";
        echo "  mode: " . ($r['mode'] ?? 'MISSING') . "\n";
        echo "  projectEndDate: " . ($r['projectEndDate'] ?? 'MISSING') . "\n";
        echo "  amount: " . ($r['amount'] ?? 'MISSING') . "\n";
        exit;
    }
}
echo "BR/2026/0043 not found.\n";
