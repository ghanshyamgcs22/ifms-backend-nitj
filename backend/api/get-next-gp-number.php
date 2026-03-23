<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

try {
    $db = getMongoDBConnection();
    
    if (!$db) {
        throw new Exception('Failed to connect to MongoDB');
    }
    
    // Determine current financial year
    $currentDate = new DateTime();
    $currentMonth = (int) $currentDate->format('n'); // 1-12
    $currentYear = (int) $currentDate->format('Y');
    
    if ($currentMonth >= 4) {
        // April onwards - current year to next year
        $fyStart = $currentYear;
        $fyEnd = $currentYear + 1;
    } else {
        // January to March - previous year to current year
        $fyStart = $currentYear - 1;
        $fyEnd = $currentYear;
    }
    
    // Format: YY-YY (e.g., 25-26)
    $financialYear = sprintf(
        "%02d-%02d",
        $fyStart % 100,
        $fyEnd % 100
    );
    
    // Find the last GP number for this financial year
    // Use regex to match GP/YY-YY/XXX format
    $regex = new MongoDB\BSON\Regex("^GP/{$financialYear}/", 'i');
    
    $lastProject = $db->projects->findOne(
        ['gpNumber' => $regex],
        [
            'sort' => ['gpNumber' => -1],
            'projection' => ['gpNumber' => 1]
        ]
    );
    
    $newNumber = 1; // Default starting number
    
    if ($lastProject && isset($lastProject['gpNumber'])) {
        // Extract sequence number from GP/YY-YY/XXX
        $parts = explode('/', $lastProject['gpNumber']);
        if (count($parts) === 3) {
            $lastNumber = intval($parts[2]);
            $newNumber = $lastNumber + 1;
        }
    }
    
    // Generate new GP number
    $newGPNumber = sprintf("GP/%s/%03d", $financialYear, $newNumber);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'gpNumber' => $newGPNumber,
            'financialYear' => $financialYear,
            'sequenceNumber' => $newNumber,
            'lastGPNumber' => $lastProject['gpNumber'] ?? null
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Get Next GP Number Error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>