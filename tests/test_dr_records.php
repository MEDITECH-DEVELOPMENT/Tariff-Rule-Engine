<?php

require __DIR__ . '/../vendor/autoload.php';

use Database\Database;
use Service\TariffCalculator;
use Service\MedpraxService;
use Service\MedpraxConfig;

// Test request WITH doctor information
$requestWithDoctor = [
    "discipline" => "014A",
    "role" => "03",
    "service_date" => "2026-02-22",
    "doctor" => [
        "name" => "Dr Jane Smith",
        "pcns" => "0248630",
        "role" => "03"
    ],
    "main_procedure" => [
        "code" => "2471",
        "description" => "Hysterectomy"
    ],
    "times" => ["start" => "07:53", "end" => "09:45"],
    "patient" => ["dob" => "1985-01-01", "weight_kg" => 109, "height_cm" => 170],
    "emergency_flag" => true,
    "diagnoses" => ["D25.9", "E11.9"],
    "procedures" => [
        ["code" => "1221", "msrs" => [["priceGroupCode" => "MSR24", "numberOfUnits" => 30, "tariffRatePublished" => 20.19]]]
    ]
];

// Test request WITHOUT doctor information (fallback)
$requestWithoutDoctor = [
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
    "diagnoses" => ["D25.9"],
    "procedures" => [
        ["code" => "1221", "msrs" => [["priceGroupCode" => "MSR24", "numberOfUnits" => 30, "tariffRatePublished" => 20.19]]]
    ]
];

echo "Testing DR Records Generation\n";
echo "==============================================\n\n";

try {
    $db = new Database();
    $medpraxConfig = new MedpraxConfig();
    $medpraxService = new MedpraxService($medpraxConfig);
    $calculator = new TariffCalculator($db, $medpraxService);

    // Test WITH doctor
    echo "TEST 1: WITH DOCTOR INFORMATION\n";
    echo "----------------------------------------------\n";
    $response1 = $calculator->calculate($requestWithDoctor);
    
    echo "EDI Payload:\n";
    $lines1 = explode("\n", $response1['edi_payload']);
    foreach ($lines1 as $idx => $line) {
        $parts = explode('|', $line);
        $recordType = $parts[0] ?? '';
        echo sprintf("%2d. [%2s] %s\n", $idx + 1, $recordType, $line);
    }
    
    echo "\n==============================================\n\n";
    
    // Test WITHOUT doctor
    echo "TEST 2: WITHOUT DOCTOR INFORMATION (Fallback)\n";
    echo "----------------------------------------------\n";
    $response2 = $calculator->calculate($requestWithoutDoctor);
    
    echo "EDI Payload (first 10 lines):\n";
    $lines2 = explode("\n", $response2['edi_payload']);
    foreach (array_slice($lines2, 0, 10) as $idx => $line) {
        $parts = explode('|', $line);
        $recordType = $parts[0] ?? '';
        echo sprintf("%2d. [%2s] %s\n", $idx + 1, $recordType, $line);
    }
    
    echo "\n==============================================\n\n";
    
    // Count DR records
    $drCount1 = count(array_filter($lines1, fn($l) => strpos($l, 'DR|') === 0));
    $drCount2 = count(array_filter($lines2, fn($l) => strpos($l, 'DR|') === 0));
    
    echo "SUMMARY:\n";
    echo "Test 1 - DR Records: $drCount1 (should match number of line items)\n";
    echo "Test 1 - Line Items: " . count($response1['line_items']) . "\n";
    echo "Test 2 - DR Records: $drCount2 (should match number of line items)\n";
    echo "Test 2 - Line Items: " . count($response2['line_items']) . "\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
