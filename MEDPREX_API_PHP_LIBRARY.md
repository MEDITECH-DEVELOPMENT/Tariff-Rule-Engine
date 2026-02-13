# Medprax API PHP Library from Joomla.

<?php

use Joomla\CMS\Factory;

class Medpraxapi
{

    var $authUrl = 'https://auth.api.medprax.co.za/api/v1/authenticate/login/username';

    var $apiUrlSchemes = 'https://schemes.api.medprax.co.za/api/v1';
    var $apiUrlProducts = 'https://products.api.medprax.co.za/api/v1';
    var $apiUrlTariffs = 'https://tariffs.api.medprax.co.za/api/v1';

    var $username = 'software@uouiouio.co.za';
    var $password = 'ouiouio';
    var $apiYear = '2026';

    private function getAPIKey($reference = 'SCHEME')
    {

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->authUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => '{
            "UniqueReferenceHost": "MEDPRAX.' . $reference . '.API",
            "userName": "' . $this->username . '",
            "password": "' . $this->password . '"

            }',
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json'
            ),
        ));

        $apikey = curl_exec($curl);
        $apikey = json_decode($apikey)->token;

        curl_close($curl);
        return $apikey;
    }

    private function curlHandler($url, $service, $body, $reference = 'SCHEME', $type = 'POST', $year = null)
    {
        $year ? null : $year = $this->apiYear;

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url . $service,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $type,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $this->getAPIKey($reference),
                'Content-Type: application/json',
                'Year: ' . $year
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        return $response;
    }

    public function getScheme($location, $scheme = null, $limit = 10, $page = 1)
    {
        switch ($location) {
            case 'MEDPRAX':
                $service = "/schemes/scheme/list?filterjoin=Or&asc=true&orderPropertyName=Name&pageIndex=1&pageSize=100";
                $body = '[{
                    "propertyName": "Name",
                    "Operation": "Contains",
                    "Value": "' . $scheme . '"
                },
               { "propertyName": "Code",
                    "Operation": "Contains",
                    "Value": "' . $scheme . '"}
                ]';

                $results = $this->curlHandler($service, $body);
                $schemes = json_encode(json_decode($results)->Schemes->PageResult);
                break;

            case 'LOCAL':
                $db = JFactory::getDBO();

                if ($scheme) {
                    $sql = "select * from dev_medprax_schemes
                    where name like '%$scheme%' 
                    or code like '%$scheme%'
                    limit $limit";
                } else {
                    $sql = "select * from dev_medprax_schemes order by `name` asc ";
                }

                $schemes = $db->setQuery($sql)->loadObjectList();
                $schemes = json_encode($schemes);
        }

        return $schemes;
    }

    public function syncGetSchemes($page = 1, $limit = 500)
    {
        $service = "/schemes/search/$page/$limit";
        $body = '{"sortKey": "name"}';

        $results = $this->curlHandler($this->apiUrlSchemes, $service, $body);

        return json_decode($results);
    }


    public function syncGetSchemeAdministrators($page = 1, $limit = 500)
    {
        $service = "/schemeadministrators/search/$page/$limit";
        $body = '{"sortKey": "name"}';

        $results = $this->curlHandler($this->apiUrlSchemes, $service, $body);

        return json_decode($results);
    }


    public function syncGetSchemePlans($page = 1, $limit = 500)
    {
        $service = "/plans/search/$page/$limit";
        $body = '{"sortKey": "option"}';

        $results = $this->curlHandler($this->apiUrlSchemes, $service, $body);

        return json_decode($results);
    }


    public function syncGetSchemePlanOptions($page = 1, $limit = 500)
    {
        $service = "/planoptions/search/$page/$limit";
        $body = '{"sortKey": "option"}';

        $results = $this->curlHandler($this->apiUrlSchemes, $service, $body);

        return json_decode($results);
    }

    public function syncGetProductIcd10s($page =  1, $limit = 500)
    {
        $service = "/icd10s/search/$page/$limit";
        $body = '{"sortKey": "code"}';

        $results = $this->curlHandler($this->apiUrlProducts, $service, $body, 'PRODUCT');

        return json_decode($results);
    }

    public function syncGetDisciplineCodes($type, $page = 1, $limit = 500)
    {
        $service = "/disciplines/$type/search/$page/$limit";
        $body = '{"sortKey": "code"}';

        $results = $this->curlHandler($this->apiUrlTariffs, $service, $body, 'TARIFF');

        return json_decode($results);
    }

    public function syncGetTariffCodes($type, $page = 1, $limit = 500)
    {
        $service = "/tariffcodes/$type/search/$page/$limit";
        $body = '{"sortKey": "code"}';

        $results = $this->curlHandler($this->apiUrlTariffs, $service, $body, 'TARIFF');

        return json_decode($results);
    }

    public function syncGetSchemePlanSubOptions($page = 1, $limit = 500)
    {
        $service = "/plansuboptions/search/$page/$limit";
        $body = '{"sortKey": "code"}';

        $results = $this->curlHandler($this->apiUrlSchemes, $service, $body);

        return json_decode($results);
    }

    public function syncGetSchemePlanSubOption($code)
    {
        $service = "/plansuboptions/$code";
        $body = '{"sortKey": "code"}';

        $results = $this->curlHandler($this->apiUrlSchemes, $service, $body, 'SCHEME', 'GET');

        return json_decode($results);
    }

    public function syncGetTariffMsr($type, $tariffCode, $planOption, $disciplineCode, $serviceDate)
    {
        // check cache first
        // update if msr is older than 5 days
        $expiryDate = date('Y-m-d');
        //echo $expiryDate;

        // set default tarrif code for cash clients
        // if $tariffCode is empty set to 'CASH'
        
        if (empty($planOption)) {
            $planOption = '39I';
        }

        $year = date('Y', strtotime($serviceDate));

        $db = JFactory::getDBO();
        
        
        $cacheMsr = $db->setQuery("select * from dev_medprax_tariff_msr where 
                                    type = '$type'
                                    and tariffCode ='$tariffCode'
                                    and planOption = '$planOption'
                                    and disciplineCode = '$disciplineCode'
                                    and updated > '$expiryDate'
                                    and apiYear = '$year'
                                    order by updated desc
                                    limit 1
                                    ")->loadObject();
        
        //$cacheMsr = null;
        if (!$cacheMsr) {
            $service = "/msr/$type/list";
            $body = '{
                        "tariffCodes": [
                            "' . $tariffCode . '"
                        ],
                        "planOptionCode": "' . $planOption . '",
                        "disciplineCode": "' . $disciplineCode . '",
                        "priceGroupCode": "",
                        "model": true
                        }';
            
            $results = $this->curlHandler($this->apiUrlTariffs, $service, $body, 'TARIFF','POST',$year);

            $msr = new stdClass();
            $msr->type = $type;
            $msr->tariffCode = $tariffCode;
            $msr->planOption = $planOption;
            $msr->disciplineCode = $disciplineCode;
            $msr->description = json_decode($results)->msrs->pageResult[0]->tariffCode->description;
            $msr->raw = $results;
            $msr->apiYear = $year;
            $db->insertObject('dev_medprax_tariff_msr', $msr);
            $result = json_decode($results)->msrs->pageResult[0];
        } else {
            json_decode($cacheMsr->raw)->msrs ? $result = json_decode($cacheMsr->raw)->msrs->pageResult[0] : $result = null;
        }

        return $result;
    }

    public function getDisciplineType($disciplineCode)
    {
        $db = JFactory::getDBO();
        return $db->setQuery("select `type` from dev_medprax_discipline_codes where code = '$disciplineCode' limit 1")->loadResult();
    }
    public function getICD($code = NULL, $function = 'SEARCH')
    {
        $db = JFactory::getDBO();

        switch ($function) {
            case ('SEARCH'):
                $icd = $db->setQuery("select * from dev_medprax_product_icd10s where code like '%$code%' or description like '%$code%' limit 10")->loadObjectList();
                break;
            case ('CODE'):
                $icd = $db->setQuery("select * from dev_medprax_product_icd10s where code = '$code'")->loadObject();
                break;
        }

        return json_encode($icd);
    }

    public function getPlan($location, $scheme = null, $plan = null)
    {
        switch ($location) {
            case 'MEDPRAX':
                $service = '/schemes/plan/list?filterjoin=And&asc=true&orderPropertyName=Code&pageIndex=1&pageSize=100';
                $body = '[
                            {
                                "propertyName": "SchemeCode",
                                "Operation": "Contains",
                                "Value": "' . $plan . '",
                            },
                        ]';
                $results = $this->curlHandler($service, $body);
                $plans = json_encode(json_decode($results)->Plans->PageResult);
                break;

            case 'LOCAL':
                $db = JFactory::getDBO();
                if ($plan) {
                    $sql = "select * from dev_medprax_scheme_plans where code = '$plan'";
                } else {
                    $sql = "select * from dev_medprax_scheme_plans where schemeCode = '$scheme'";
                }
                $plans = json_encode($db->setQuery($sql)->loadObjectList());

                break;
        }
        return $plans;
    }

    public function getOption($location, $plan = null, $option = null)
    {
        switch ($location) {
            case 'MEDPRAX':
                $service = '/schemes/planoption/list?filterjoin=And&asc=true&orderPropertyName=Code&pageIndex=1&pageSize=100';
                $body = '[
                            {
                                "propertyName": "PlanCode",
                                "Operation": "Contains",
                                "Value": "' . $option . '",
                            },
                        ]';
                $results = $this->curlHandler($service, $body);
                $option = json_encode(json_decode($results)->PlanOptions->PageResult);
                break;

            case 'LOCAL':
                $db = JFactory::getDBO();
                if ($option) {
                    $sql = "select * from dev_medprax_scheme_plan_options where code = '$option'";
                } else {
                    $sql = "select * from dev_medprax_scheme_plan_options where planCode = '$plan'";
                }

                $options = json_encode($db->setQuery($sql)->loadObjectList());
        }
        return $options;
    }


    public function getSubOption($location, $subOption, $option = null)
    {
        switch ($location) {

            case 'LOCAL':
                $db = JFactory::getDBO();
                if ($subOption) {
                    $sql = "select * from dev_medprax_scheme_plan_suboptions where code = '$subOption'";
                } else {
                    $sql = "select id,code from dev_medprax_scheme_plan_suboptions where optionCode = '$option'";
                }

                $subOptions = json_encode($db->setQuery($sql)->loadObjectList());
        }
        return $subOptions;
    }


    public function getTariff($type, $tariffCode, $planOption, $disciplineCode, $search = null, $serviceDate = null)
    {
        $serviceDate ? null : $serviceDate = date('Y-m-d');


        $db = JFactory::getDBO();

        if ($search) {
            $search = "or description like '%$tariffCode%'";
        }

        $tariffs = $db->setQuery("select * from dev_medprax_tariff_codes where 
                                    `type` = '$type'
                                    and(
                                    code ='$tariffCode' $search
                                    
                                    )
                                    ")->loadObjectList();
        $ts = [];
        $results = [];

        foreach ($tariffs as $t) {
            //$ts[] = $t->code;

            $results[] = $this->syncGetTariffMsr($type, $t->code, $planOption, $disciplineCode, $serviceDate);
        }

        return json_encode($results);
    }

    public function getTariffs($type, $tariffCode, $planOption, $disciplineCode, $search = null, $serviceDate = null)
    {
        $serviceDate ? null : $serviceDate = date('Y-m-d');

        $db = JFactory::getDBO();

        $searchFilter = '';
        if ($search) {
            $searchFilter = " or description like '%$tariffCode%'";
        }

        $tariffs = $db->setQuery("select * from dev_medprax_tariff_codes where 
                                    `type` = '$type'
                                    and(
                                    code like '%$tariffCode%' $searchFilter
                                    
                                    ) limit 20
                                    ")->loadObjectList();
        $ts = [];
        $tarrifResults = [];

        foreach ($tariffs as $t) {
            //$ts[] = $t->code;

            $tarrifResults[] = $this->syncGetTariffMsr($type, $t->code, $planOption, $disciplineCode, $serviceDate);
        }

        $medicines = $this->searchMedicines($search, 1, 20);
        $materials = $this->searchMaterials($search, 1, 20);

        $res = new stdClass();
        $res->tariffs = $tarrifResults;
        $medicines ? $res->medicines = json_decode($medicines) : $res->medicines = null;
        $materials ? $res->materials = json_decode($materials) : $res->materials = null;

        return json_encode($res);
    }

    public function getTariffByCode($code, $disciplineCode)
    {
        $db = JFactory::getDBO();
        $tariff = $db->setQuery("select * from dev_medprax_tariff_codes where code = '$code' and type = '$disciplineCode' limit 1")->loadObject();

        return $tariff;
    }

    public function getTariffbyPlan($tariff)
    {
        $service = '/schemes/planoption/list?filterjoin=And&asc=true&orderPropertyName=Code&pageIndex=1&pageSize=100';
        $body = '[
                    {
                        "propertyName": "PlanCode",
                        "Operation": "Contains",
                        "Value": "' . $tariff . '",
                    },
                ]';
        $results = $this->curlHandler($service, $body);
        $tariff = json_encode(json_decode($results)->Tariffs->PageResult);
        return $tariff;
    }

    public function planToScheme($code)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $scheme = $db->setQuery("select dev_medprax_schemes.name as name, dev_medprax_schemes.code as code
                                from dev_medprax_scheme_plans
                                join dev_medprax_schemes
                                on dev_medprax_schemes.code = dev_medprax_scheme_plans.schemeCode
                                where dev_medprax_scheme_plans.code = '$code' ")->loadObject();
        return $scheme;
    }

    public function searchMedicines($search, $page, $limit)
    {
        $service = "/medicines/search/$page/$limit";
        $body = '{
                    "filters": [
                        {
                        "propertyName": "Name",
                        "operation": "Contains",
                        "value": "' . $search . '"
                        },
                        {
                        "propertyName": "NappiCode",
                        "operation": "Contains",
                        "value": "' . $search . '"
                        }
                    ],
                    "filterJoin": "Or",
                    "ascendingOrder": true,
                    "sortKey": "code"
                    }';

        $results = $this->curlHandler($this->apiUrlProducts, $service, $body, 'PRODUCT', 'POST');
        return $results;
    }

    public function searchMaterials($search, $page, $limit)
    {
        $service = "/materials/search/$page/$limit";
        $body = '{"filters": [
                        {
                        "propertyName": "Name",
                        "operation": "Contains",
                        "value": "' . $search . '"
                        },
                        {
                        "propertyName": "NappiCode",
                        "operation": "Contains",
                        "value": "' . $search . '"
                        }
                    ],
                    "filterJoin": "Or",
                "sortKey": "code"}';

        $results = $this->curlHandler($this->apiUrlProducts, $service, $body, 'PRODUCT', 'POST');
        return $results;
    }

    public function getSchemeContracts($planOption, $disciplineCode){
        $service = "/contracts/medical/options/list?model=true";
        $body = '{
                    "planOptionCode": "' . $planOption . '",
                    "disciplineCode": "' . $disciplineCode . '"
                    }';

        $results = $this->curlHandler($this->apiUrlTariffs, $service, $body, 'TARIFF', 'POST');
        
        return json_decode($results);
    }
}
