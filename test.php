<?php
// test.php - Place this in your backend folder (same level as api/ and config/)
// Run it with: php test.php

echo "=== MongoDB Connection Test ===\n\n";

// 1. Check if MongoDB extension is installed
echo "1️⃣ Checking MongoDB Extension:\n";
if (extension_loaded('mongodb')) {
    echo "✅ MongoDB extension is installed\n";
    echo "   Version: " . phpversion('mongodb') . "\n\n";
} else {
    echo "❌ MongoDB extension NOT installed!\n\n";
    die();
}

// 2. Check file structure
echo "2️⃣ Checking File Structure:\n";
echo "Current directory: " . __DIR__ . "\n";

$configPath = __DIR__ . '/config/database.php';
echo "Looking for: $configPath\n";

if (file_exists($configPath)) {
    echo "✅ database.php found\n\n";
} else {
    echo "❌ database.php NOT found!\n";
    echo "   Please ensure this structure:\n";
    echo "   backend/\n";
    echo "   ├── test.php (this file)\n";
    echo "   ├── config/\n";
    echo "   │   └── database.php\n";
    echo "   └── api/\n";
    echo "       └── departments.php\n\n";
    die();
}

// 3. Test database connection
echo "3️⃣ Testing Database Connection:\n";
require_once $configPath;

try {
    $db = new Database();
    echo "✅ Database class instantiated\n";
    
    $connection = $db->getConnection();
    echo "✅ Connection object created\n";
    
    // Test ping
    $command = new MongoDB\Driver\Command(['ping' => 1]);
    $cursor = $connection->executeCommand('admin', $command);
    $response = current($cursor->toArray());
    
    if ($response->ok == 1) {
        echo "✅ MongoDB Atlas is responding!\n\n";
    }
    
} catch (Exception $e) {
    echo "❌ Connection failed: " . $e->getMessage() . "\n\n";
    die();
}

// 4. Check database name
echo "4️⃣ Database Info:\n";
echo "Database name: " . $db->getDatabaseName() . "\n\n";

// 5. Check if departments collection exists
echo "5️⃣ Checking Collections:\n";
try {
    $command = new MongoDB\Driver\Command(['listCollections' => 1]);
    $cursor = $connection->executeCommand($db->getDatabaseName(), $command);
    
    $collections = [];
    foreach ($cursor as $collection) {
        $collections[] = $collection->name;
    }
    
    if (empty($collections)) {
        echo "⚠️  No collections found in database\n\n";
    } else {
        echo "Found collections: " . implode(", ", $collections) . "\n";
    }
    
    if (in_array('departments', $collections)) {
        echo "✅ departments collection exists\n\n";
    } else {
        echo "⚠️  departments collection NOT found\n";
        echo "   Creating it with sample data...\n";
        
        // Create collection with sample data
        $bulk = new MongoDB\Driver\BulkWrite;
        $bulk->insert([
            'name' => 'Computer Science',
            'hodName' => 'Dr. Rajesh Kumar',
            'hodEmail' => 'rajesh.kumar@ifms.edu',
            'createdAt' => new MongoDB\BSON\UTCDateTime(),
            'updatedAt' => new MongoDB\BSON\UTCDateTime()
        ]);
        $bulk->insert([
            'name' => 'Electrical Engineering',
            'hodName' => 'Dr. Priya Sharma',
            'hodEmail' => 'priya.sharma@ifms.edu',
            'createdAt' => new MongoDB\BSON\UTCDateTime(),
            'updatedAt' => new MongoDB\BSON\UTCDateTime()
        ]);
        $bulk->insert([
            'name' => 'Mechanical Engineering',
            'hodName' => 'Dr. Amit Patel',
            'hodEmail' => 'amit.patel@ifms.edu',
            'createdAt' => new MongoDB\BSON\UTCDateTime(),
            'updatedAt' => new MongoDB\BSON\UTCDateTime()
        ]);
        
        $result = $connection->executeBulkWrite($db->getCollection('departments'), $bulk);
        echo "✅ Created departments collection!\n";
        echo "   Inserted " . $result->getInsertedCount() . " sample departments\n\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n\n";
}

// 6. Count and display all departments
echo "6️⃣ Current Departments:\n";
try {
    $query = new MongoDB\Driver\Query([]);
    $cursor = $connection->executeQuery($db->getCollection('departments'), $query);
    
    $count = 0;
    foreach ($cursor as $document) {
        $count++;
        echo "   $count. {$document->name} (HOD: {$document->hodName})\n";
    }
    
    echo "\n📊 Total departments: $count\n\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n\n";
}

// 7. Final checks
echo "7️⃣ API Readiness:\n";
$apiPath = __DIR__ . '/api/departments.php';
if (file_exists($apiPath)) {
    echo "✅ departments.php exists\n";
} else {
    echo "❌ departments.php NOT found at: $apiPath\n";
}

echo "\n=== Test Complete ===\n";
echo "\n✨ Next Steps:\n";
echo "1. Start backend server: php -S localhost:8000\n";
echo "2. Test API: http://localhost:8000/api/departments.php\n";
echo "3. Start your frontend and test the form\n";
?>