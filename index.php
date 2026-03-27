<?php
header('Content-Type: application/json');
echo json_encode([
    "status" => "success",
    "message" => "IFMS Backend API: Healthy",
    "version" => "1.1.5",
    "php_version" => phpversion()
]);
?>
