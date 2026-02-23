<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

$db = getMongoDBConnection();
$filesCollection = $db->project_files;

try {
    $fileId = $_GET['id'] ?? null;
    $projectId = $_GET['projectId'] ?? null;
    $gpNumber = $_GET['gpNumber'] ?? null;
    
    // Find file by ID or by project details
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
        throw new Exception('File ID, Project ID, or GP Number is required');
    }
    
    if (!$file) {
        throw new Exception('File not found');
    }
    
    // Get physical file path
    $filePath = __DIR__ . '/../../' . $file['filePath'];
    
    if (!file_exists($filePath)) {
        throw new Exception('Physical file not found on server');
    }
    
    // Set headers for file download
    header('Content-Type: ' . $file['mimeType']);
    header('Content-Disposition: attachment; filename="' . $file['fileName'] . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: 0');
    
    // Output file
    readfile($filePath);
    exit;
    
} catch (Exception $e) {
    error_log("File Download Error: " . $e->getMessage());
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>