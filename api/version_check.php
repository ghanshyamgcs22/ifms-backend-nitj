<?php
require_once __DIR__ . '/../vendor/autoload.php';
echo "MongoDB Library Version: " . \MongoDB\Client::VERSION . "\n";
echo "MongoDB Extension Version: " . phpversion('mongodb') . "\n";
?>
