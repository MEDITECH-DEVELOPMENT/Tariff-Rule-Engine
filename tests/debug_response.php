<?php

use Service\MedpraxConfig;
use Service\MedpraxService;

require_once __DIR__ . '/../vendor/autoload.php';

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

$config = new MedpraxConfig();
$service = new MedpraxService($config);

$type = 'medical';
$tariffCode = '0023';  // Use 0023 for "Time Units"
$disciplineCode = '014A'; 
$planOption = '39I'; 

$result = $service->getTariffMsr($type, $tariffCode, $planOption, $disciplineCode);

$json = json_encode($result, JSON_PRETTY_PRINT);
echo "Full Response:\n";
echo $json;
