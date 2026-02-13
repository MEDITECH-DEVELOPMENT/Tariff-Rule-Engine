<?php

use Service\TariffCalculator;
use Service\MedpraxConfig;
use Service\MedpraxService;
use Database\Database;

require __DIR__ . '/../vendor/autoload.php';

// Instantiate dependencies
$db = new Database();
$config = new MedpraxConfig();
$medpraxService = new MedpraxService($config);

// Calculator registers strategy in constructor now
$calculator = new TariffCalculator($db, $medpraxService);

// Create request payload WITHOUT prices (to test hydration)
$payload = [
    "discipline" => "014A",
    "role" => "03", // Anaesthetist
    "pmb_requested_rate" => 3.0, // Request 300% if PMB (Test PMB Multiplier)
    "emergency_flag" => true,    // Test Emergency Modifier 0011
    "times" => [
        "start" => "12:40",
        "end" => "14:00" // 80 mins
    ],
    "patient" => [
        "dob" => "1993-03-07",
        "weight_kg" => 89,
        "height_cm" => 160
    ],
    "diagnoses" => [
        "O68.2", // Obstetric Emergency - Likely PMB
        "O82.0"
    ],
    "procedures" => [
        [
            "code" => "2615",
            // NO MSRS provided - Calculator should fetch them
        ],
        [
            "code" => "0023", 
             // NO MSRS
        ]
    ]
];

echo "---------------------------------------------------\n";
echo "Testing Invoice Calculation with Server-Side Lookup\n";
echo "---------------------------------------------------\n";
echo "Input Payload (Procedures only):\n";
foreach ($payload['procedures'] as $p) {
    echo " - Code: " . $p['code'] . " (No Price)\n";
}
echo "Emergency Flag: " . ($payload['emergency_flag'] ? 'TRUE' : 'FALSE') . "\n";
echo "PMB Requested Rate: x" . $payload['pmb_requested_rate'] . "\n";

echo "\nCalculating...\n";

try {
    $result = $calculator->calculate($payload);

    echo "\n---------------------------------------------------\n";
    echo "Calculation Result:\n";
    echo "Total Amount: R " . number_format($result['total_amount'], 2) . "\n";
    echo "Is PMB: " . ($result['is_pmb'] ? "Yes" : "No") . "\n";
    
    echo "\nTrace Log:\n";
    foreach ($result['trace'] as $line) {
        echo " > " . $line . "\n";
    }

} catch (Exception $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
}
