<?php
/**
 * API Calculation Endpoint
 *
 * Accepts JSON payload with claim details.
 * Returns calculated results.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Service\TariffCalculator;
use Service\MedpraxConfig;
use Service\MedpraxService;
use Database\Database;

// Headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// Get JSON Body
$input = file_get_contents('php://input');
$payload = json_decode($input, true);

if (!$payload) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON Payload']);
    exit;
}

try {
    // Dependency Injection
    $db = new Database();
    $config = new MedpraxConfig();
    $medpraxService = new MedpraxService($config);

    // Instantiate Calculator (Strategy auto-registered in constructor)
    $calculator = new TariffCalculator($db, $medpraxService);

    // Calculate
    $result = $calculator->calculate($payload);

    // Return Success
    echo json_encode([
        'success' => true,
        'data' => $result
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString() // Careful exposing trace in prod
    ]);
}
