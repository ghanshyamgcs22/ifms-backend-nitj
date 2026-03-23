<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use MongoDB\Client;
use Dotenv\Dotenv;

// Load .env from backend parent root
$dotenv = Dotenv::createImmutable(dirname(dirname(__DIR__)));
$dotenv->load();


class Database {

    private $atlas_uri;
    private $db_name;
    private $client;

    public function __construct() {
        $this->atlas_uri = $_ENV['MONGODB_ATLAS_URI'] ?? '';
        $this->db_name   = $_ENV['MONGODB_DB_NAME'] ?? 'research_projects';
        $this->connect();
    }

    private function connect() {
        try {
            if (empty($this->atlas_uri)) {
                die(json_encode([
                    "success" => false,
                    "message" => "MongoDB Atlas URI not found in .env file"
                ]));
            }

            $this->client = new Client($this->atlas_uri);
            // $this->client->listDatabases();

            error_log("✅ MongoDB connected successfully");

        } catch (Exception $e) {
            error_log("❌ MongoDB connection failed: " . $e->getMessage());
            die(json_encode([
                "success" => false,
                "message" => "MongoDB connection failed"
            ]));
        }
    }

    public function getDatabase() {
        return $this->client->{$this->db_name};
    }
}

function getMongoDBConnection() {
    static $db = null;

    if ($db === null) {
        $database = new Database();
        $db = $database->getDatabase();
    }

    return $db;
}
