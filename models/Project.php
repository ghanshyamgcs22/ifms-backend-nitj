<?php
/**
 * Project Model - Updated with Financial Year GP Number Format
 * GP Format: GP/YY-YY/XXX (e.g., GP/25-26/001)
 * Financial Year: April to March
 * 
 * New Fields: amountBookedByPI, actualExpenditure
 */
class Project {
    private $collection;
    private $db;

    public function __construct($db) {
        $this->db = $db;
        $this->collection = $db->projects;
    }

    /**
     * Generate unique GP Number based on financial year
     * Format: GP/YY-YY/XXX
     * Financial Year: April (4) to March (3)
     */
    private function generateGPNumber() {
        $currentDate = new DateTime();
        $currentMonth = (int) $currentDate->format('n'); // 1-12
        $currentYear = (int) $currentDate->format('Y');
        
        // Determine financial year
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
        $regex = new MongoDB\BSON\Regex("^GP/{$financialYear}/", 'i');
        $lastProject = $this->collection->findOne(
            ['gpNumber' => $regex],
            ['sort' => ['gpNumber' => -1]]
        );

        if ($lastProject && isset($lastProject['gpNumber'])) {
            // Extract sequence number from GP/YY-YY/XXX
            $parts = explode('/', $lastProject['gpNumber']);
            $lastNumber = intval(end($parts));
            $newNumber = $lastNumber + 1;
        } else {
            // First project of this financial year
            $newNumber = 1;
        }

        return sprintf("GP/%s/%03d", $financialYear, $newNumber);
    }

    /**
     * Calculate project duration in years
     */
    private function calculateDuration($startDate, $endDate) {
        if (!$startDate || !$endDate) {
            return 0;
        }
        
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $interval = $start->diff($end);
        
        $years = $interval->y + ($interval->m / 12) + ($interval->d / 365.25);
        return round($years, 2);
    }

    /**
     * Create a new project
     */
    public function create($data) {
        try {
            if (!$data || !is_array($data)) {
                throw new Exception("Invalid data received");
            }

            if (!empty($data['gpNumber'])) {
                if (!preg_match('/^GP\/\d{2}-\d{2}\/\d{3}$/', $data['gpNumber'])) {
                    throw new Exception("Invalid GP Number format. Expected: GP/YY-YY/XXX (e.g., GP/25-26/001)");
                }
                $gpNumber = $data['gpNumber'];
                
                $existing = $this->collection->findOne(['gpNumber' => $gpNumber]);
                if ($existing) {
                    throw new Exception("GP Number {$gpNumber} already exists");
                }
            } else {
                $gpNumber = $this->generateGPNumber();
            }

            $startDate = null;
            $endDate = null;
            
            if (!empty($data['projectStartYear']) && !empty($data['projectStartMonth']) && !empty($data['projectStartDate'])) {
                $startDate = new DateTime(
                    $data['projectStartYear'] . '-' . 
                    str_pad($data['projectStartMonth'], 2, '0', STR_PAD_LEFT) . '-' . 
                    str_pad($data['projectStartDate'], 2, '0', STR_PAD_LEFT)
                );
            }

            if (!empty($data['projectEndYear']) && !empty($data['projectEndMonth']) && !empty($data['projectEndDate'])) {
                $endDate = new DateTime(
                    $data['projectEndYear'] . '-' . 
                    str_pad($data['projectEndMonth'], 2, '0', STR_PAD_LEFT) . '-' . 
                    str_pad($data['projectEndDate'], 2, '0', STR_PAD_LEFT)
                );
            }

            $totalYears = 0;
            if ($startDate && $endDate) {
                $totalYears = $this->calculateDuration(
                    $startDate->format('Y-m-d'),
                    $endDate->format('Y-m-d')
                );
            } elseif (!empty($data['totalYears'])) {
                $totalYears = floatval($data['totalYears']);
            }

            $totalAllocatedAmount = 0;
            $headsInfo = [];
            if (isset($data['allocations']) && is_array($data['allocations'])) {
                foreach ($data['allocations'] as $allocation) {
                    $sanctionedAmount = floatval($allocation['sanctionedAmount'] ?? 0);
                    $totalAllocatedAmount += $sanctionedAmount;
                    
                    $headsInfo[] = [
                        'headId' => $allocation['headId'] ?? null,
                        'headName' => htmlspecialchars(strip_tags($allocation['headName'] ?? '')),
                        'headType' => htmlspecialchars(strip_tags($allocation['headType'] ?? '')),
                        'sanctionedAmount' => $sanctionedAmount
                    ];
                }
            }

            $document = [
                'gpNumber' => $gpNumber,
                'isOldProject' => !empty($data['isOldProject']),
                'modeOfProject' => htmlspecialchars(strip_tags($data['modeOfProject'] ?? '')),
                'projectName' => htmlspecialchars(strip_tags($data['projectName'] ?? '')),
                'projectAgencyName' => htmlspecialchars(strip_tags($data['projectAgencyName'] ?? '')),
                'sanctionOrderNo' => htmlspecialchars(strip_tags($data['sanctionOrderNo'] ?? '')),
                'nameOfScheme' => htmlspecialchars(strip_tags($data['nameOfScheme'] ?? '')),
                'piName' => htmlspecialchars(strip_tags($data['piName'] ?? '')),
                'piEmail' => htmlspecialchars(strip_tags($data['piEmail'] ?? '')),
                'department' => htmlspecialchars(strip_tags($data['department'] ?? '')),
                
                'projectStartDate' => $startDate ? new MongoDB\BSON\UTCDateTime($startDate->getTimestamp() * 1000) : null,
                'projectEndDate' => $endDate ? new MongoDB\BSON\UTCDateTime($endDate->getTimestamp() * 1000) : null,
                'originalEndDate' => $endDate ? new MongoDB\BSON\UTCDateTime($endDate->getTimestamp() * 1000) : null,
                'hasExtension' => false,
                
                'totalYears' => $totalYears,
                
                'totalSanctionedAmount' => floatval($data['totalSanctionedAmount'] ?? 0),
                'totalAllocatedAmount' => $totalAllocatedAmount,
                'totalReleasedAmount' => 0,
                'amountBookedByPI' => 0,
                'actualExpenditure' => 0,
                'bankDetails' => 'Canara Bank',
                
                'heads' => $headsInfo,
                
                'sanctionedLetterFile' => null,
                'sanctionedLetterFileName' => null,
                'sanctionedLetterUploadedAt' => null,
                
                'status' => htmlspecialchars(strip_tags($data['status'] ?? 'pending')),
                
                'createdAt' => new MongoDB\BSON\UTCDateTime(),
                'updatedAt' => new MongoDB\BSON\UTCDateTime()
            ];

            if (!$document['projectName'] || !$document['piName'] || !$document['department']) {
                throw new Exception("Missing required fields");
            }

            $result = $this->collection->insertOne($document);
            $projectId = (string) $result->getInsertedId();

            if (isset($data['allocations']) && !empty($data['allocations'])) {
                $this->createAllocations($projectId, $gpNumber, $data['allocations'], $totalAllocatedAmount);
            }

            return [
                'insertedId' => $projectId,
                'gpNumber' => $gpNumber
            ];

        } catch (Exception $e) {
            error_log("Project creation failed: " . $e->getMessage());
            throw new Exception("Project creation failed: " . $e->getMessage());
        }
    }

    /**
     * Create fund allocations for project
     */
    private function createAllocations($projectId, $gpNumber, $allocations, $totalAllocated) {
        $formattedAllocations = [];
        
        foreach ($allocations as $alloc) {
            $formattedAllocations[] = [
                'id' => (string) new MongoDB\BSON\ObjectId(),
                'headId' => $alloc['headId'] ?? null,
                'headName' => htmlspecialchars(strip_tags($alloc['headName'] ?? '')),
                'headType' => htmlspecialchars(strip_tags($alloc['headType'] ?? '')),
                'sanctionedAmount' => floatval($alloc['sanctionedAmount'] ?? 0),
                'releasedAmount' => 0,
                'remainingAmount' => floatval($alloc['sanctionedAmount'] ?? 0),
                'timePeriod' => htmlspecialchars(strip_tags($alloc['timePeriod'] ?? '1 Year')),
                'bankDetails' => 'Canara Bank',
                'status' => 'sanctioned'
            ];
        }
        
        $allocationsData = [
            'projectId' => $projectId,
            'gpNumber' => $gpNumber,
            'allocations' => $formattedAllocations,
            'totalAllocated' => $totalAllocated,
            'totalReleased' => 0,
            'createdAt' => new MongoDB\BSON\UTCDateTime(),
            'updatedAt' => new MongoDB\BSON\UTCDateTime()
        ];
        
        $this->db->fund_allocations->insertOne($allocationsData);
    }

    /**
     * Get all projects with their allocations and files
     */
    public function getAll() {
        $pipeline = [
            [
                '$project' => [
                    'sanctionedLetterFile' => 0,
                    'extensionLetterFile'  => 0,
                ]
            ],
            [
                '$lookup' => [
                    'from' => 'fund_allocations',
                    'localField' => '_id',
                    'foreignField' => 'projectId',
                    'as' => 'allocations',
                    'pipeline' => [
                        [
                            '$project' => [
                                'projectId' => ['$toString' => '$projectId'],
                                'gpNumber' => 1,
                                'allocations' => 1,
                                'totalAllocated' => 1,
                                'totalReleased' => 1,
                                'createdAt' => 1,
                                'updatedAt' => 1
                            ]
                        ]
                    ]
                ]
            ],
            [
                '$lookup' => [
                    'from' => 'project_files',
                    'let' => ['projectId' => ['$toString' => '$_id']],
                    'pipeline' => [
                        [
                            '$match' => [
                                '$expr' => ['$eq' => ['$projectId', '$$projectId']]
                            ]
                        ],
                        [
                            '$project' => [
                                'id' => ['$toString' => '$_id'],
                                'projectId' => 1,
                                'gpNumber' => 1,
                                'fileName' => 1,
                                'storedFileName' => 1,
                                'fileType' => 1,
                                'filePath' => 1,
                                'fileSize' => 1,
                                'mimeType' => 1,
                                'uploadedAt' => 1,
                                'uploadedBy' => 1
                            ]
                        ]
                    ],
                    'as' => 'files'
                ]
            ],
            [
                '$sort' => ['createdAt' => -1]
            ]
        ];

        $cursor = $this->collection->aggregate($pipeline);
        return iterator_to_array($cursor);
    }

    /**
     * Search projects
     */
    public function search($searchTerm = "", $status = "") {
        $filter = [];

        if (!empty($searchTerm)) {
            $filter['$or'] = [
                ['projectName' => ['$regex' => $searchTerm, '$options' => 'i']],
                ['gpNumber' => ['$regex' => $searchTerm, '$options' => 'i']],
                ['piName' => ['$regex' => $searchTerm, '$options' => 'i']],
                ['piEmail' => ['$regex' => $searchTerm, '$options' => 'i']],
                ['department' => ['$regex' => $searchTerm, '$options' => 'i']]
            ];
        }

        if (!empty($status)) {
            $filter['status'] = $status;
        }

        $pipeline = [
            ['$match' => $filter],
            [
                '$project' => [
                    'sanctionedLetterFile' => 0,
                    'extensionLetterFile'  => 0,
                ]
            ],
            [
                '$lookup' => [
                    'from' => 'fund_allocations',
                    'localField' => '_id',
                    'foreignField' => 'projectId',
                    'as' => 'allocations',
                    'pipeline' => [
                        [
                            '$project' => [
                                'projectId' => ['$toString' => '$projectId'],
                                'gpNumber' => 1,
                                'allocations' => 1,
                                'totalAllocated' => 1,
                                'totalReleased' => 1
                            ]
                        ]
                    ]
                ]
            ],
            [
                '$lookup' => [
                    'from' => 'project_files',
                    'let' => ['projectId' => ['$toString' => '$_id']],
                    'pipeline' => [
                        [
                            '$match' => [
                                '$expr' => ['$eq' => ['$projectId', '$$projectId']]
                            ]
                        ],
                        [
                            '$project' => [
                                'id' => ['$toString' => '$_id'],
                                'projectId' => 1,
                                'gpNumber' => 1,
                                'fileName' => 1,
                                'storedFileName' => 1,
                                'fileType' => 1,
                                'filePath' => 1,
                                'fileSize' => 1,
                                'mimeType' => 1,
                                'uploadedAt' => 1,
                                'uploadedBy' => 1
                            ]
                        ]
                    ],
                    'as' => 'files'
                ]
            ],
            ['$sort' => ['createdAt' => -1]]
        ];

        $cursor = $this->collection->aggregate($pipeline);
        return iterator_to_array($cursor);
    }

    /**
     * Get project by ID
     */
    public function getOne($id) {
        $pipeline = [
            ['$match' => ['_id' => new MongoDB\BSON\ObjectId($id)]],
            [
                '$lookup' => [
                    'from' => 'fund_allocations',
                    'localField' => '_id',
                    'foreignField' => 'projectId',
                    'as' => 'allocations',
                    'pipeline' => [
                        [
                            '$project' => [
                                'projectId' => ['$toString' => '$projectId'],
                                'gpNumber' => 1,
                                'allocations' => 1,
                                'totalAllocated' => 1,
                                'totalReleased' => 1
                            ]
                        ]
                    ]
                ]
            ],
            [
                '$lookup' => [
                    'from' => 'project_files',
                    'let' => ['projectId' => ['$toString' => '$_id']],
                    'pipeline' => [
                        [
                            '$match' => [
                                '$expr' => ['$eq' => ['$projectId', '$$projectId']]
                            ]
                        ],
                        [
                            '$project' => [
                                'id' => ['$toString' => '$_id'],
                                'projectId' => 1,
                                'gpNumber' => 1,
                                'fileName' => 1,
                                'storedFileName' => 1,
                                'fileType' => 1,
                                'filePath' => 1,
                                'fileSize' => 1,
                                'mimeType' => 1,
                                'uploadedAt' => 1,
                                'uploadedBy' => 1
                            ]
                        ]
                    ],
                    'as' => 'files'
                ]
            ]
        ];

        $cursor = $this->collection->aggregate($pipeline);
        $results = iterator_to_array($cursor);
        return !empty($results) ? $results[0] : null;
    }

    /**
     * Update project
     */
    public function update($id, $data) {
        $updateData = [
            '$set' => [
                'updatedAt' => new MongoDB\BSON\UTCDateTime()
            ]
        ];

        if (isset($data['modeOfProject'])) {
            $updateData['$set']['modeOfProject'] = htmlspecialchars(strip_tags($data['modeOfProject']));
        }
        if (isset($data['projectName'])) {
            $updateData['$set']['projectName'] = htmlspecialchars(strip_tags($data['projectName']));
        }
        if (isset($data['projectAgencyName'])) {
            $updateData['$set']['projectAgencyName'] = htmlspecialchars(strip_tags($data['projectAgencyName']));
        }
        if (isset($data['sanctionOrderNo'])) {
            $updateData['$set']['sanctionOrderNo'] = htmlspecialchars(strip_tags($data['sanctionOrderNo']));
        }
        if (isset($data['nameOfScheme'])) {
            $updateData['$set']['nameOfScheme'] = htmlspecialchars(strip_tags($data['nameOfScheme']));
        }
        if (isset($data['piName'])) {
            $updateData['$set']['piName'] = htmlspecialchars(strip_tags($data['piName']));
        }
        if (isset($data['piEmail'])) {
            $updateData['$set']['piEmail'] = htmlspecialchars(strip_tags($data['piEmail']));
        }
        if (isset($data['department'])) {
            $updateData['$set']['department'] = htmlspecialchars(strip_tags($data['department']));
        }
        if (isset($data['totalSanctionedAmount'])) {
            $updateData['$set']['totalSanctionedAmount'] = floatval($data['totalSanctionedAmount']);
        }
        if (isset($data['totalReleasedAmount'])) {
            $updateData['$set']['totalReleasedAmount'] = floatval($data['totalReleasedAmount']);
        }
        if (isset($data['amountBookedByPI'])) {
            $updateData['$set']['amountBookedByPI'] = floatval($data['amountBookedByPI']);
        }
        if (isset($data['actualExpenditure'])) {
            $updateData['$set']['actualExpenditure'] = floatval($data['actualExpenditure']);
        }
        if (isset($data['status'])) {
            $updateData['$set']['status'] = htmlspecialchars(strip_tags($data['status']));
        }
        
        if (isset($data['gpNumber'])) {
            if (!preg_match('/^GP\/\d{2}-\d{2}\/\d{3}$/', $data['gpNumber'])) {
                throw new Exception("Invalid GP Number format. Expected: GP/YY-YY/XXX");
            }
            $updateData['$set']['gpNumber'] = $data['gpNumber'];
        }

        if (isset($data['projectStartYear']) && isset($data['projectStartMonth']) && isset($data['projectStartDate'])) {
            $startDate = new DateTime(
                $data['projectStartYear'] . '-' . 
                str_pad($data['projectStartMonth'], 2, '0', STR_PAD_LEFT) . '-' . 
                str_pad($data['projectStartDate'], 2, '0', STR_PAD_LEFT)
            );
            $updateData['$set']['projectStartDate'] = new MongoDB\BSON\UTCDateTime($startDate->getTimestamp() * 1000);
        }

        if (isset($data['projectEndYear']) && isset($data['projectEndMonth']) && isset($data['projectEndDate'])) {
            $endDate = new DateTime(
                $data['projectEndYear'] . '-' . 
                str_pad($data['projectEndMonth'], 2, '0', STR_PAD_LEFT) . '-' . 
                str_pad($data['projectEndDate'], 2, '0', STR_PAD_LEFT)
            );
            $updateData['$set']['projectEndDate'] = new MongoDB\BSON\UTCDateTime($endDate->getTimestamp() * 1000);
        }

        if (isset($data['totalYears'])) {
            $updateData['$set']['totalYears'] = floatval($data['totalYears']);
        }

        $result = $this->collection->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($id)],
            $updateData
        );

        return $result->getModifiedCount() > 0 || $result->getMatchedCount() > 0;
    }

    /**
     * Delete project
     */
    public function delete($id) {
        $result = $this->collection->deleteOne(['_id' => new MongoDB\BSON\ObjectId($id)]);
        
        if ($result->getDeletedCount() > 0) {
            $this->db->fund_allocations->deleteMany(['projectId' => $id]);
            $this->db->project_files->deleteMany(['projectId' => $id]);
        }
        
        return $result->getDeletedCount() > 0;
    }

    /**
     * Format document for API response.
     *
     * FIXED: The old version called ->toDateTime() unconditionally, which crashes
     * when a date field contains a plain string (e.g. saved by the old extend-project.php).
     * Now we check instanceof before calling toDateTime(), so stale string-dates
     * are passed through as-is and never cause a fatal error.
     */
    public static function formatDocument($doc) {
        if ($doc === null) return null;

        $formatted = (array) $doc;
        $formatted['id'] = (string) $doc['_id'];
        unset($formatted['_id']);

        // All date fields that might exist on a project document
        $dateFields = [
            'createdAt',
            'updatedAt',
            'projectStartDate',
            'projectEndDate',
            'originalEndDate',
            'sanctionedLetterUploadedAt',
            'extensionLetterUploadedAt',
            'lastExtendedAt',
        ];

        foreach ($dateFields as $field) {
            if (!isset($formatted[$field]) || $formatted[$field] === null) {
                continue;
            }

            $val = $formatted[$field];

            if ($val instanceof MongoDB\BSON\UTCDateTime) {
                // Normal case — convert to a readable date string
                $formatted[$field] = $val->toDateTime()->format('Y-m-d H:i:s');
            }
            // If it's already a string (legacy data), leave it untouched.
            // Any other unexpected type is also left untouched — no crash.
        }

        // Format allocations if present
        if (isset($formatted['allocations']) && is_array($formatted['allocations'])) {
            $formatted['allocations'] = array_map(function($alloc) {
                if (isset($alloc['_id'])) {
                    $alloc['id'] = (string) $alloc['_id'];
                    unset($alloc['_id']);
                }
                return $alloc;
            }, $formatted['allocations']);
        }

        // Format files if present
        if (isset($formatted['files']) && is_array($formatted['files'])) {
            $formatted['files'] = array_map(function($file) {
                if (isset($file['_id'])) {
                    unset($file['_id']);
                }
                if (isset($file['uploadedAt']) && $file['uploadedAt'] instanceof MongoDB\BSON\UTCDateTime) {
                    $file['uploadedAt'] = $file['uploadedAt']->toDateTime()->format('Y-m-d H:i:s');
                }
                return $file;
            }, $formatted['files']);
        }

        return $formatted;
    }
}
?>