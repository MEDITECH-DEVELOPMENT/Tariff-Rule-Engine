<?php

use Service\MedpraxConfig;
use Service\MedpraxService;

// Include Composer Autoload (assumes vendor/autoload.php exists)
require_once __DIR__ . '/../vendor/autoload.php';

// Ensure the necessary classes are strictly autoloadable
if (!class_exists('Service\\MedpraxConfig')) {
    require_once __DIR__ . '/../src/Service/MedpraxConfig.php';
}

if (!class_exists('Service\\MedpraxService')) {
    if (file_exists(__DIR__ . '/../src/Service/MedpraxService.php')) {
        require_once __DIR__ . '/../src/Service/MedpraxService.php';
    } else {
        die("MedpraxService.php not found\n");
    }
}

try {
    echo "Initializing Configuration...\n";
    $config = new MedpraxConfig();
    
    echo "Initializing Service...\n";
    $service = new MedpraxService($config);
    
    $type = 'medical';
    $tariffCode = '0023'; 
    $disciplineCode = '014A'; // Anaesthetics
    $planOption = '39I'; // Cash/Discovery Classic
    
    echo "--------------------------------------------------\n";
    echo "Endpoint: tariffs.api.medprax.co.za/api/v1/msr/$type/list\n";
    echo "Parameters:\n";
    echo " - Tariff: $tariffCode\n";
    echo " - Discipline: $disciplineCode\n";
    echo " - Plan Option: $planOption\n";
    
    $result = $service->getTariffMsr($type, $tariffCode, $planOption, $disciplineCode);
    
    if ($result && isset($result->msrs->pageResult) && count($result->msrs->pageResult) > 0) {
        $data = $result->msrs->pageResult[0];
        // The second item often contains the detailed unit types breakdown if the first is the summary
        $details = $data; 
        
        echo "\nSUCCESS: Retrieved MSR Data\n";
        echo "Description: " . ($data->tariffCode->description ?? 'N/A') . "\n";
        
        // Check for published rates in the nested list if available, or top level
        // The structure showed a list of unit types in the response I saw earlier.
        // Let's dump the first few keys of pricing
        
        $foundPrice = false;
        // In the debug output, I saw 'tariffRatePublished' inside an array... wait.
        // The array I saw in debug output was `pageResult`. 
        // Inside `pageResult[0]`, there might be `tariffModifiers` or something?
        
        // Let's just output the first calculated valid price found to verify.
        echo "Raw Response Snippet (First 500 chars):\n";
        echo substr(json_encode($data, JSON_PRETTY_PRINT), 0, 500) . "...\n";
            
    } else {
        echo "FAILED: No valid result returned.\n";
        if (isset($result->message)) echo "Message: " . $result->message . "\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
