<?php

require __DIR__ . '/../vendor/autoload.php';

use Database\Database;
use Service\TariffCalculator;
use Service\MedpraxService;
use Service\MedpraxConfig;

// Test request with diagnoses
$request = [
    "discipline" => "014A",
    "role" => "03",
    "service_date" => "2026-02-22",
    "main_procedure" => [
        "code" => "2471",
        "description" => "Hysterectomy"
    ],
    "times" => ["start" => "07:53", "end" => "09:45"],
    "patient" => ["dob" => "1985-01-01", "weight_kg" => 109, "height_cm" => 170],
    "emergency_flag" => false,
    "diagnoses" => ["D25.9", "E11.9"],
    "procedures" => [
        ["code" => "1221", "msrs" => [["priceGroupCode" => "MSR24", "numberOfUnits" => 30, "tariffRatePublished" => 20.19]]]
    ]
];

echo "Testing EDI Payload Generation\n";
echo "==============================================\n\n";

try {
    $db = new Database();
    $medpraxConfig = new MedpraxConfig();
    $medpraxService = new MedpraxService($medpraxConfig);
    $calculator = new TariffCalculator($db, $medpraxService);

    $response = $calculator->calculate($request);

    echo "EDI PAYLOAD:\n";
    echo "----------------------------------------------\n";
    echo $response['edi_payload'];
    echo "\n----------------------------------------------\n\n";

    echo "BREAKDOWN:\n";
    $lines = explode("\n", $response['edi_payload']);
    foreach ($lines as $idx => $line) {
        $parts = explode('|', $line);
        $recordType = $parts[0] ?? '';
        echo sprintf("%2d. [%2s] %s\n", $idx + 1, $recordType, $line);
    }

    echo "\n\nLINE ITEMS:\n";
    foreach ($response['line_items'] as $item) {
        echo sprintf("  - %s: %s (%.2f units @ R%.2f = R%.2f)\n", 
            $item['code'], 
            $item['description'], 
            $item['units'], 
            $item['unit_price'], 
            $item['total']
        );
    }

    echo "\nTotal: R" . $response['total_amount'] . "\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
