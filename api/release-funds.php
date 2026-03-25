<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$possiblePaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
];

$vendorLoaded = false;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $vendorLoaded = true;
        break;
    }
}

if (!$vendorLoaded) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'MongoDB library not found. Please run: composer require mongodb/mongodb'
    ]);
    exit();
}

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

try {
    $client = new Client("");
    $db = $client->research_projects;
    $releasedFundsCollection = $db->released_funds;
    $budgetRequestsCollection = $db->budget_requests;
    $projectsCollection = $db->projects;

    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true);

    switch ($method) {
        case 'GET':
            // Get all sanctioned projects (completed budget requests)
            $completedRequests = $budgetRequestsCollection->find([
                'status' => 'approved',
                'currentStage' => 'completed'
            ])->toArray();

            $sanctionedProjects = [];
            
            foreach ($completedRequests as $request) {
                // Get all releases for this GP Number
                $releases = $releasedFundsCollection->find([
                    'gpNumber' => $request->gpNumber
                ])->toArray();
                
                // Calculate total released amount
                $releasedTotal = 0;
                $releaseDetails = [];
                
                foreach ($releases as $release) {
                    $releasedTotal += $release->releaseAmount ?? 0;
                    $releaseDetails[] = [
                        '_id' => (string)$release->_id,
                        'releaseAmount' => $release->releaseAmount ?? 0,
                        'letterDate' => $release->letterDate ?? '',
                        'letterNumber' => $release->letterNumber ?? '',
                        'releasedBy' => $release->releasedBy ?? '',
                        'releasedAt' => isset($release->releasedAt) ? 
                            $release->releasedAt->toDateTime()->format('Y-m-d H:i:s') : '',
                        'remarks' => $release->remarks ?? ''
                    ];
                }

                $sanctionedAmount = $request->amount ?? 0;
                $availableToRelease = $sanctionedAmount - $releasedTotal;

                $sanctionedProjects[] = [
                    '_id' => (string)$request->_id,
                    'gpNumber' => $request->gpNumber,
                    'projectTitle' => $request->projectTitle ?? '',
                    'piName' => $request->piName ?? '',
                    'piEmail' => $request->piEmail ?? '',
                    'department' => $request->department ?? '',
                    'purpose' => $request->purpose ?? '',
                    'description' => $request->description ?? '',
                    'invoiceNumber' => $request->invoiceNumber ?? '',
                    'sanctionedAmount' => $sanctionedAmount,
                    'releasedAmount' => $releasedTotal,
                    'availableToRelease' => $availableToRelease,
                    'status' => 'sanctioned',
                    'releaseCount' => count($releases),
                    'releases' => $releaseDetails,
                    'approvedAt' => isset($request->ao2ApprovedAt) ? 
                        $request->ao2ApprovedAt->toDateTime()->format('Y-m-d H:i:s') : ''
                ];
            }

            echo json_encode([
                'success' => true,
                'data' => $sanctionedProjects,
                'count' => count($sanctionedProjects)
            ]);
            break;

        case 'POST':
            // Release funds for a sanctioned project
            if (!isset($input['gpNumber']) || !isset($input['releaseAmount'])) {
                throw new Exception('GP Number and Release Amount are required');
            }

            $gpNumber = $input['gpNumber'];
            $releaseAmount = floatval($input['releaseAmount']);
            $letterDate = $input['letterDate'] ?? date('Y-m-d');
            $letterNumber = $input['letterNumber'] ?? '';
            $releasedBy = $input['releasedBy'] ?? 'Finance Officer';
            $remarks = $input['remarks'] ?? '';

            // Validate letterNumber is provided
            if (empty($letterNumber)) {
                throw new Exception('Letter Number is required');
            }

            // Get the budget request to validate
            $budgetRequest = $budgetRequestsCollection->findOne([
                'gpNumber' => $gpNumber,
                'status' => 'approved',
                'currentStage' => 'completed'
            ]);

            if (!$budgetRequest) {
                throw new Exception('Sanctioned project not found for GP Number: ' . $gpNumber);
            }

            // Calculate total already released
            $releases = $releasedFundsCollection->find([
                'gpNumber' => $gpNumber
            ])->toArray();
            
            $releasedTotal = 0;
            foreach ($releases as $release) {
                $releasedTotal += $release->releaseAmount ?? 0;
            }

            $sanctionedAmount = $budgetRequest->amount ?? 0;
            $availableAmount = $sanctionedAmount - $releasedTotal;

            if ($releaseAmount <= 0) {
                throw new Exception('Release amount must be greater than zero');
            }

            if ($releaseAmount > $availableAmount) {
                throw new Exception("Release amount (₹{$releaseAmount}) exceeds available amount (₹{$availableAmount})");
            }

            // Check for duplicate letter number
            $duplicateLetter = $releasedFundsCollection->findOne([
                'letterNumber' => $letterNumber
            ]);

            if ($duplicateLetter) {
                throw new Exception("Letter Number '{$letterNumber}' is already used for another release");
            }

            // Create release record with all required fields
            $releaseData = [
                // Required fields (per schema validation)
                'gpNumber' => $gpNumber,
                'projectTitle' => $budgetRequest->projectTitle ?? '',
                'piName' => $budgetRequest->piName ?? '',
                'piEmail' => $budgetRequest->piEmail ?? '',
                'department' => $budgetRequest->department ?? '',
                'releaseAmount' => $releaseAmount,
                'letterDate' => $letterDate,
                'letterNumber' => $letterNumber,
                'releasedBy' => $releasedBy,
                'releasedAt' => new UTCDateTime(),
                
                // Additional information
                'purpose' => $budgetRequest->purpose ?? '',
                'description' => $budgetRequest->description ?? '',
                'invoiceNumber' => $budgetRequest->invoiceNumber ?? '',
                'remarks' => $remarks,
                
                // Financial tracking
                'sanctionedAmount' => $sanctionedAmount,
                'previouslyReleased' => $releasedTotal,
                'totalReleasedAfter' => $releasedTotal + $releaseAmount,
                'remainingAmount' => $availableAmount - $releaseAmount,
                
                // Reference
                'budgetRequestId' => (string)$budgetRequest->_id,
                'projectType' => $budgetRequest->projectType ?? '',
                
                // Timestamps
                'createdAt' => new UTCDateTime(),
                'updatedAt' => new UTCDateTime()
            ];

            $result = $releasedFundsCollection->insertOne($releaseData);

            if (!$result->getInsertedId()) {
                throw new Exception('Failed to create release record');
            }

            // Update project's released amount if exists
            $project = $projectsCollection->findOne(['gpNumber' => $gpNumber]);
            if ($project) {
                $projectsCollection->updateOne(
                    ['gpNumber' => $gpNumber],
                    [
                        '$set' => [
                            'releasedAmount' => $releasedTotal + $releaseAmount,
                            'updatedAt' => new UTCDateTime()
                        ]
                    ]
                );
            }

            echo json_encode([
                'success' => true,
                'message' => 'Funds released successfully',
                'data' => [
                    '_id' => (string)$result->getInsertedId(),
                    'gpNumber' => $gpNumber,
                    'releaseAmount' => $releaseAmount,
                    'letterNumber' => $letterNumber,
                    'totalReleased' => $releasedTotal + $releaseAmount,
                    'remaining' => $availableAmount - $releaseAmount
                ]
            ]);
            break;

        case 'DELETE':
            // Delete a release record (for corrections)
            if (!isset($_GET['id'])) {
                throw new Exception('Release ID is required');
            }

            $releaseId = new ObjectId($_GET['id']);
            $release = $releasedFundsCollection->findOne(['_id' => $releaseId]);

            if (!$release) {
                throw new Exception('Release record not found');
            }

            // Delete the release
            $result = $releasedFundsCollection->deleteOne(['_id' => $releaseId]);

            if ($result->getDeletedCount() === 0) {
                throw new Exception('Failed to delete release record');
            }

            // Update project's released amount
            $project = $projectsCollection->findOne(['gpNumber' => $release->gpNumber]);
            if ($project) {
                $newReleasedAmount = max(0, ($project->releasedAmount ?? 0) - ($release->releaseAmount ?? 0));
                $projectsCollection->updateOne(
                    ['gpNumber' => $release->gpNumber],
                    [
                        '$set' => [
                            'releasedAmount' => $newReleasedAmount,
                            'updatedAt' => new UTCDateTime()
                        ]
                    ]
                );
            }

            echo json_encode([
                'success' => true,
                'message' => 'Release record deleted successfully'
            ]);
            break;

        default:
            throw new Exception('Invalid request method');
    }

} catch (Exception $e) {
    error_log("Released Funds API Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
