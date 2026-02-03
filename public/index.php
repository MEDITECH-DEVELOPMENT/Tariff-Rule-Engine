<?php

require __DIR__ . '/../vendor/autoload.php';

use Database\Database;
use Service\TariffCalculator;
use Domain\Strategies\AnaestheticStrategy014A;

header('Content-Type: application/json');

try {
    // 1. Setup Dependencies
    $db = new Database();
    $calculator = new TariffCalculator($db);

    // 2. Register Strategies
    $calculator->registerStrategy(new AnaestheticStrategy014A($db));

    // 3. Get Request Body
    $input = file_get_contents('php://input');
    $payload = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("Invalid JSON Payload");
    }

    if (empty($payload)) {
        // Just a health check if no payload
        echo json_encode(['status' => 'ok', 'message' => 'Tariff Engine Ready']);
        exit;
    }

    // 4. Calculate
    $response = $calculator->calculate($payload);

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}

