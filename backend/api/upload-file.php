<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

$db = getMongoDBConnection();
$filesCollection = $db->project_files;
$projectsCollection = $db->projects;

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'POST':
            // Handle file upload
            if (!isset($_FILES['file'])) {
                throw new Exception('No file uploaded');
            }
            
            $file = $_FILES['file'];
            $projectId = $_POST['projectId'] ?? null;
            $gpNumber = $_POST['gpNumber'] ?? null;
            $fileType = $_POST['fileType'] ?? 'sanction_letter';
            $uploadedBy = $_POST['uploadedBy'] ?? 'admin_user';
            
            if (!$projectId || !$gpNumber) {
                throw new Exception('Project ID and GP Number are required');
            }
            
            // Validate file
            $allowedTypes = ['application/pdf'];
            if (!in_array($file['type'], $allowedTypes)) {
                throw new Exception('Only PDF files are allowed');
            }
            
            // Check file size (max 10MB)
            $maxSize = 10 * 1024 * 1024; // 10MB
            if ($file['size'] > $maxSize) {
                throw new Exception('File size must be less than 10MB');
            }
            
            // Create upload directory if it doesn't exist
            $uploadBaseDir = __DIR__ . '/../../uploads/projects/';
            $uploadDir = $uploadBaseDir . $gpNumber . '/';
            
            if (!file_exists($uploadBaseDir)) {
                mkdir($uploadBaseDir, 0777, true);
            }
            
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            // Generate unique filename
            $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $fileName = $fileType . '_' . time() . '.' . $fileExtension;
            $filePath = $uploadDir . $fileName;
            
            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                throw new Exception('Failed to upload file');
            }
            
            // Store file information in database
            $document = [
                'projectId' => $projectId,
                'gpNumber' => $gpNumber,
                'fileName' => $file['name'],
                'storedFileName' => $fileName,
                'fileType' => $fileType,
                'filePath' => '/uploads/projects/' . $gpNumber . '/' . $fileName,
                'fileSize' => $file['size'],
                'mimeType' => $file['type'],
                'uploadedAt' => new MongoDB\BSON\UTCDateTime(),
                'uploadedBy' => $uploadedBy
            ];
            
            $result = $filesCollection->insertOne($document);
            
            // Update project with sanctionedLetterFile field
            $projectsCollection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($projectId)],
                [
                    '$set' => [
                        'sanctionedLetterFile' => $document['filePath'],
                        'sanctionedLetterFileName' => $file['name'],
                        'sanctionedLetterUploadedAt' => new MongoDB\BSON\UTCDateTime(),
                        'updatedAt' => new MongoDB\BSON\UTCDateTime()
                    ]
                ]
            );
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'File uploaded successfully',
                'fileId' => (string) $result->getInsertedId(),
                'fileName' => $file['name'],
                'filePath' => $document['filePath'],
                'fileSize' => $file['size']
            ]);
            break;

        case 'GET':
            // Get files for a project
            $projectId = $_GET['projectId'] ?? null;
            $gpNumber = $_GET['gpNumber'] ?? null;
            $fileId = $_GET['id'] ?? null;
            
            if ($fileId) {
                // Get single file
                $file = $filesCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($fileId)]);
                
                if (!$file) {
                    throw new Exception('File not found');
                }
                
                $file['id'] = (string) $file['_id'];
                unset($file['_id']);
                
                if (isset($file['uploadedAt'])) {
                    $file['uploadedAt'] = $file['uploadedAt']->toDateTime()->format('Y-m-d H:i:s');
                }
                
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'data' => $file
                ]);
            } else {
                // Get all files for project
                $filter = [];
                if ($projectId) {
                    $filter['projectId'] = $projectId;
                }
                if ($gpNumber) {
                    $filter['gpNumber'] = $gpNumber;
                }
                
                $cursor = $filesCollection->find($filter, [
                    'sort' => ['uploadedAt' => -1]
                ]);
                
                $files = [];
                foreach ($cursor as $file) {
                    $file['id'] = (string) $file['_id'];
                    unset($file['_id']);
                    
                    if (isset($file['uploadedAt'])) {
                        $file['uploadedAt'] = $file['uploadedAt']->toDateTime()->format('Y-m-d H:i:s');
                    }
                    
                    $files[] = $file;
                }
                
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'data' => $files,
                    'count' => count($files)
                ]);
            }
            break;

        case 'DELETE':
            // Delete file
            $id = $_GET['id'] ?? null;
            
            if (!$id) {
                throw new Exception('File ID is required');
            }
            
            // Get file info
            $file = $filesCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($id)]);
            
            if (!$file) {
                throw new Exception('File not found');
            }
            
            // Delete physical file
            $physicalPath = __DIR__ . '/../../' . $file['filePath'];
            if (file_exists($physicalPath)) {
                unlink($physicalPath);
            }
            
            // Delete database record
            $result = $filesCollection->deleteOne(['_id' => new MongoDB\BSON\ObjectId($id)]);
            
            // Update project to remove file reference
            $projectsCollection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($file['projectId'])],
                [
                    '$unset' => [
                        'sanctionedLetterFile' => '',
                        'sanctionedLetterFileName' => '',
                        'sanctionedLetterUploadedAt' => ''
                    ],
                    '$set' => [
                        'updatedAt' => new MongoDB\BSON\UTCDateTime()
                    ]
                ]
            );
            
            if ($result->getDeletedCount() > 0) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'File deleted successfully'
                ]);
            } else {
                throw new Exception('Failed to delete file');
            }
            break;

        default:
            http_response_code(405);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Method not allowed'
            ]);
            break;
    }

} catch (Exception $e) {
    error_log("File Upload Error: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>