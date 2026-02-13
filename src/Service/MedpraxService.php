<?php

namespace Service;

class MedpraxService
{
    private MedpraxConfig $config;
    private array $tokens = [];

    public function __construct(MedpraxConfig $config)
    {
        $this->config = $config;
    }

    private function getAPIKey(string $reference = 'SCHEME'): string
    {
        if (isset($this->tokens[$reference])) {
            return $this->tokens[$reference];
        }

        $curl = curl_init();
        $hostRef = "MEDPRAX.{$reference}.API";

        $postData = json_encode([
            "UniqueReferenceHost" => $hostRef,
            "userName" => $this->config->username,
            "password" => $this->config->password
        ]);

        curl_setopt_array($curl, [
            CURLOPT_URL => $this->config->authUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            throw new \Exception("cURL Error obtaining API Key: " . $err);
        }

        $decoded = json_decode($response);
        if (!isset($decoded->token)) {
            throw new \Exception("Failed to obtain API token. Response: " . $response);
        }

        $this->tokens[$reference] = $decoded->token;
        return $decoded->token;
    }

    private function curlHandler(
        string $baseUrl,
        string $endpoint,
        string $body = '',
        string $reference = 'SCHEME',
        string $method = 'POST',
        ?string $year = null
    ): string {
        $year = $year ?? $this->config->apiYear;
        $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');

        $token = $this->getAPIKey($reference);

        $curl = curl_init();
        
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Year: ' . $year
        ];

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            throw new \Exception("cURL Error accessing {$url}: " . $err);
        }

        return $response;
    }

    public function getTariffMsr(
        string $type, 
        $tariffCodes, 
        string $planOptionCode = '39I', 
        string $disciplineCode = '014A', 
        ?string $serviceDate = null
    ) {
        if (is_string($tariffCodes)) {
            $tariffCodes = array($tariffCodes);
        }
        $year = $serviceDate ? date('Y', strtotime($serviceDate)) : $this->config->apiYear;
        $endpoint = "/msr/{$type}/list";
        $body = json_encode([
            "tariffCodes" => $tariffCodes,
            "planOptionCode" => $planOptionCode,
            "disciplineCode" => $disciplineCode,
            "priceGroupCode" => "", 
            "model" => true
        ]);
        $response = $this->curlHandler(
            $this->config->apiUrlTariffs,
            $endpoint,
            $body,
            'TARIFF', 
            'POST',
            $year
        );
        return json_decode($response);
    }
    
    public function searchIcd10(string $code)
    {
        $body = json_encode([
            "sortKey" => "code",
            "filters" => [
                [
                    "propertyName" => "Code",
                    "operation" => "Equals",
                    "value" => $code
                ]
            ],
            "filterJoin" => "And"
        ]);
        try {
            $response = $this->curlHandler(
                $this->config->apiUrlProducts,
                '/icd10s/search/1/1', 
                $body,
                'PRODUCT', 
                'POST'
            );
            $result = json_decode($response);
            if (isset($result->icd10s->pageResult) && count($result->icd10s->pageResult) > 0) {
                return $result->icd10s->pageResult[0]; 
            }
        } catch (\Exception $e) {
            error_log("MedpraxService ICD Lookup Failed: " . $e->getMessage());
        }
        return null;
    }

    public function searchIcd10ByTerm(string $term, int $limit = 20, ?string $serviceDate = null)
    {
        // Extract year from service date/year input
        $year = $serviceDate ? date('Y', strtotime($serviceDate)) : $this->config->apiYear;
        
        $body = json_encode([
            "sortKey" => "code",
            "filters" => [
                [
                    "propertyName" => "Code",
                    "operation" => "Contains",
                    "value" => $term
                ],
                [
                    "propertyName" => "Description",
                    "operation" => "Contains",
                    "value" => $term
                ]
            ],
            "filterJoin" => "Or"
        ]);
        try {
            $response = $this->curlHandler(
                $this->config->apiUrlProducts,
                "/icd10s/search/1/{$limit}",
                $body,
                'PRODUCT',
                'POST',
                $year
            );
            return json_decode($response);
        } catch (\Exception $e) {
            error_log("ICD Search By Term Failed: " . $e->getMessage());
            return (object)['error' => $e->getMessage()];
        }
    }

    public function searchTariffs(string $term, int $limit = 20, ?string $serviceDate = null)
    {
        $type = 'medical'; 
        // Extract year from service date/year input
        $year = $serviceDate ? date('Y', strtotime($serviceDate)) : $this->config->apiYear;
        
        $body = json_encode([
            "sortKey" => "code",
            "filters" => [
                [
                    "propertyName" => "Code",
                    "operation" => "Contains",
                    "value" => $term
                ],
                [
                    "propertyName" => "Description",
                    "operation" => "Contains",
                    "value" => $term
                ]
            ],
            "filterJoin" => "Or"
        ]);
        try {
            $response = $this->curlHandler(
                $this->config->apiUrlTariffs,
                "/tariffcodes/{$type}/search/1/{$limit}", 
                $body,
                'TARIFF',
                'POST',
                $year
            );
            return json_decode($response);
        } catch (\Exception $e) {
            error_log("Tariff Search Failed: " . $e->getMessage());
            return (object)['error' => $e->getMessage()];
        }
    }
}
