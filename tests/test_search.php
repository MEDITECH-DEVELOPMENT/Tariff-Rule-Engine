<?php

require __DIR__ . '/../vendor/autoload.php';

use Service\MedpraxConfig;
use Service\MedpraxService;

// Setup
$config = new MedpraxConfig();
$service = new MedpraxService($config);

// Test 1: ICD-10 Search (Verified working)
echo "Testing ICD-10 Search for 'flu':\n";
$icdResult = $service->searchIcd10ByTerm('flu', 2);
if ($icdResult && isset($icdResult->icd10s->pageResult)) {
    foreach ($icdResult->icd10s->pageResult as $item) {
        $code = $item->code ?? 'N/A';
        $desc = $item->description ?? 'No Description';
        $pmb = !empty($item->isPmb) || !empty($item->isPMB) ? "[PMB]" : "";
        echo " - Found: $code ($desc) $pmb\n";
    }
} else {
    echo " - No results or Error.\n";
}

echo "\n-------------------------------------------------\n";

// Test 2: Tariff Search Debug
$term = '2615'; // Specific code
echo "Testing Tariff Search for '{$term}' with endpoint /tariffcodes/medical/search/1/limit :\n";
$tariffResult = $service->searchTariffs($term, 2); 

echo "\nRAW DUMP:\n";
print_r($tariffResult);
echo "\n-------------------------------------------------\n";

$list = [];
if (isset($tariffResult->codes->pageResult)) $list = $tariffResult->codes->pageResult;
elseif (isset($tariffResult->tariffCodes->pageResult)) $list = $tariffResult->tariffCodes->pageResult;
elseif (isset($tariffResult->pageResult)) $list = $tariffResult->pageResult;

if (!empty($list)) {
    foreach ($list as $item) {
        $code = $item->code ?? 'N/A';
        $desc = $item->longDescription ?? $item->description ?? 'No Description';
        echo " - Found: $code ($desc)\n";
    }
} else {
    echo " - No parsed results.\n";
}
