<?php
// ─────────────────────────────────────────────
// ExamiPortal — Database Config
// liproh.com / Hostinger
// ─────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'u111778052_examiuser');
define('DB_PASS', 'Sarthi@admin654321');        // ← replace with your actual password
define('DB_NAME', 'u111778052_examiportal');
define('API_BASE', 'https://liproh.com/portal/api');
define('SITE_URL', 'https://liproh.com/portal');

function db(): mysqli {
    static $conn;
    if (!$conn) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $conn->set_charset('utf8mb4');
        if ($conn->connect_error) {
            http_response_code(500);
            echo json_encode(['error' => 'DB connection failed: ' . $conn->connect_error]);
            exit;
        }
    }
    return $conn;
}

// CORS + JSON headers for all API responses
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
