<?php

require __DIR__ . '/../vendor/autoload.php';

use Database\Database;
use Service\TariffCalculator;
use Service\MedpraxService;
use Service\MedpraxConfig;

// Test request WITH emergency flag
$requestWithEmergency = [
    "discipline" => "014A",
    "role" => "03",
    "service_date" => "2026-02-22",
    "main_procedure" => [
        "code" => "2471",
        "description" => "Hysterectomy"
    ],
    "times" => ["start" => "07:53", "end" => "09:45"],
    "patient" => ["dob" => "1985-01-01", "weight_kg" => 109, "height_cm" => 170],
    "emergency_flag" => true, // EMERGENCY
    "diagnoses" => ["D25.9"],
    "procedures" => [
        ["code" => "1221", "msrs" => [["priceGroupCode" => "MSR24", "numberOfUnits" => 30, "tariffRatePublished" => 20.19]]]
    ]
];

// Test request WITHOUT emergency flag
$requestWithoutEmergency = [
    "discipline" => "014A",
    "role" => "03",
    "service_date" => "2026-02-22",
    "main_procedure" => [
        "code" => "2471",
        "description" => "Hysterectomy"
    ],
    "times" => ["start" => "07:53", "end" => "09:45"],
    "patient" => ["dob" => "1985-01-01", "weight_kg" => 109, "height_cm" => 170],
    "emergency_flag" => false, // NO EMERGENCY
    "diagnoses" => ["D25.9"],
    "procedures" => [
        ["code" => "1221", "msrs" => [["priceGroupCode" => "MSR24", "numberOfUnits" => 30, "tariffRatePublished" => 20.19]]]
    ]
];

echo "Testing Emergency Modifier (0011) as MD Record\n";
echo "==============================================\n\n";

try {
    $db = new Database();
    $medpraxConfig = new MedpraxConfig();
    $medpraxService = new MedpraxService($medpraxConfig);
    $calculator = new TariffCalculator($db, $medpraxService);

    // Test WITH emergency
    echo "TEST 1: WITH EMERGENCY FLAG\n";
    echo "----------------------------------------------\n";
    $response1 = $calculator->calculate($requestWithEmergency);
    
    echo "Line Items:\n";
    foreach ($response1['line_items'] as $item) {
        $modInfo = !empty($item['modifiers']) ? ' [Modifiers: ' . implode(', ', array_column($item['modifiers'], 'code')) . ']' : '';
        echo sprintf("  - %s: %s (%.2f units @ R%.2f = R%.2f)%s\n", 
            $item['code'], 
            $item['description'], 
            $item['units'], 
            $item['unit_price'], 
            $item['total'],
            $modInfo
        );
    }
    echo "\nTotal: R" . $response1['total_amount'] . "\n\n";
    
    echo "EDI Payload (first 5 lines):\n";
    $lines1 = explode("\n", $response1['edi_payload']);
    foreach (array_slice($lines1, 0, 5) as $idx => $line) {
        $parts = explode('|', $line);
        $recordType = $parts[0] ?? '';
        echo sprintf("%2d. [%2s] %s\n", $idx + 1, $recordType, $line);
    }
    
    echo "\n==============================================\n\n";
    
    // Test WITHOUT emergency
    echo "TEST 2: WITHOUT EMERGENCY FLAG\n";
    echo "----------------------------------------------\n";
    $response2 = $calculator->calculate($requestWithoutEmergency);
    
    echo "Line Items:\n";
    foreach ($response2['line_items'] as $item) {
        $modInfo = !empty($item['modifiers']) ? ' [Modifiers: ' . implode(', ', array_column($item['modifiers'], 'code')) . ']' : '';
        echo sprintf("  - %s: %s (%.2f units @ R%.2f = R%.2f)%s\n", 
            $item['code'], 
            $item['description'], 
            $item['units'], 
            $item['unit_price'], 
            $item['total'],
            $modInfo
        );
    }
    echo "\nTotal: R" . $response2['total_amount'] . "\n\n";
    
    echo "EDI Payload (first 5 lines):\n";
    $lines2 = explode("\n", $response2['edi_payload']);
    foreach (array_slice($lines2, 0, 5) as $idx => $line) {
        $parts = explode('|', $line);
        $recordType = $parts[0] ?? '';
        echo sprintf("%2d. [%2s] %s\n", $idx + 1, $recordType, $line);
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
