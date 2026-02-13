<?php
/**
 * API Search Endpoint for Frontend
 * 
 * Usage:
 * GET /api/search.php?type=icd10&term=flu
 * GET /api/search.php?type=tariff&term=consultation
 */

require __DIR__ . '/../vendor/autoload.php';

use Service\MedpraxConfig;
use Service\MedpraxService;

// Headers for AJAX
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Basic Input Validation
$type = $_GET['type'] ?? '';
$term = $_GET['term'] ?? '';

if (empty($type) || empty($term)) {
    echo json_encode(['error' => 'Missing parameters: type and term are required.']);
    exit;
}

if (strlen($term) < 2) {
    echo json_encode(['results' => []]); // Too short
    exit;
}

try {
    $config = new MedpraxConfig();
    $service = new MedpraxService($config);
    $limit = 20;

    $data = [];

    if ($type === 'icd10') {
        $response = $service->searchIcd10ByTerm($term, $limit);
        if ($response && isset($response->icd10s->pageResult)) {
            // Transform for frontend dropdown (Select2 style)
            foreach ($response->icd10s->pageResult as $item) {
                $code = $item->code ?? '';
                $desc = $item->description ?? '';
                $isPmb = !empty($item->isPmb) || !empty($item->isPMB);
                
                if ($code) {
                    $itemData = [
                        'id' => $code,
                        'text' => "$code - $desc",
                        'pmb' => $isPmb
                    ];
                    if ($isPmb) $itemData['text'] .= " [PMB]";
                    $data[] = $itemData;
                }
            }
        }
    } 
    elseif ($type === 'tariff') {
        $response = $service->searchTariffs($term, $limit);
        
        $list = [];
        if (isset($response->tariffCodes->pageResult)) {
            $list = $response->tariffCodes->pageResult;
        }

        foreach ($list as $item) {
            $code = $item->code ?? '';
            $desc = $item->description ?? $item->longDescription ?? '';
            
            if ($code) {
                // Shorten description for dropdown if needed? No, user wants detail.
                $data[] = [
                    'id' => $code,
                    'text' => "$code - $desc",
                    'description' => $desc
                ];
            }
        }
    } else {
        echo json_encode(['error' => 'Invalid type. Use "icd10" or "tariff".']);
        exit;
    }

    echo json_encode(['results' => $data]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
