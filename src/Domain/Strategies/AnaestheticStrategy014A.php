<?php

namespace Domain\Strategies;

use Database\Database;
use Domain\CalculationContext;
use Domain\CalculationResult;
use Domain\DisciplineStrategy;
use Service\MedpraxService; 
use PDO;

class AnaestheticStrategy014A implements DisciplineStrategy
{
    private Database $db;
    private ?MedpraxService $medpraxService = null;

    public function __construct(Database $db, ?MedpraxService $medpraxService = null)
    {
        $this->db = $db;
        $this->medpraxService = $medpraxService;
    }

    public function supports(CalculationContext $context): bool
    {
        return $context->discipline === '014A';
    }

    private function extractMsrList(array $proc): array
    {
        $data = $proc['msrs'] ?? [];
        $list = [];
        if (is_array($data) && isset($data['pageResult'])) {
            $list = $data['pageResult'];
        } elseif (is_object($data) && isset($data->pageResult)) {
            $list = $data->pageResult;
        } elseif (is_array($data)) {
            $list = $data;
        }
        return array_map(function($item) { return (array) $item; }, (array) $list);
    }

    public function calculate(CalculationContext $context): CalculationResult
    {
        $result = new CalculationResult();
        $result->log("Starting 014A Strategy Calculation.");

        // 1. Determine Time Unit Rate (Rand per Unit)
        $timeUnitRate = 0.0;
        foreach ($context->procedures as $proc) {
            if ($proc['code'] === '0023') {
                $list = $this->extractMsrList($proc);
                $msr = $list[0] ?? null;
                if ($msr && ($msr['numberOfUnits'] ?? 0) > 0) {
                    $rate = $msr['tariffRatePublished'] ?? 0;
                    if ($rate == 0) $rate = $msr['tariffRandSchemeFixed'] ?? 0;
                    $timeUnitRate = $rate / $msr['numberOfUnits'];
                    if ($timeUnitRate > 0) break;
                }
            }
        }
        if ($timeUnitRate == 0) {
             foreach ($context->procedures as $proc) {
                if ($proc['code'] === '0023') continue;
                $list = $this->extractMsrList($proc);
                $msr = $list[0] ?? null;
                if ($msr && !empty($msr['tariffRcfPublished'])) {
                    $timeUnitRate = $msr['tariffRcfPublished']; 
                    $result->log("Derived Time RCF from Code {$proc['code']}: " . $timeUnitRate);
                    break;
                }
             }
        }
        if ($timeUnitRate == 0) {
            $timeUnitRate = 22.50; 
            $result->log("Using Fallback Time RCF: " . $timeUnitRate);
        }

        // 2. Base Time Units
        $durationMinutes = $context->getDurationMinutes();
        $baseTimeUnits = $this->calculateTimeUnits($durationMinutes);
        
        $totalTimeUnits = $baseTimeUnits;
        $result->addLineItem('0023', "Time Units (Base: {$durationMinutes} min)", $baseTimeUnits, $timeUnitRate, $baseTimeUnits * $timeUnitRate);
        $result->addAmount($baseTimeUnits * $timeUnitRate);
        
        // Modifier 0011 (Emergency)
        if ($context->emergencyFlag) {
            $emergUnits = 12.00;
            $result->addLineItem('0011', "Emergency Modifier (0011)", $emergUnits, $timeUnitRate, $emergUnits * $timeUnitRate);
            $result->addAmount($emergUnits * $timeUnitRate);
            $totalTimeUnits += $emergUnits;
        }

        // Modifier 0018 (BMI)
        $bmi = $this->calculateBMI($context->patient);
        if ($bmi >= 35) {
            $bmiUnits = $totalTimeUnits * 0.50; 
            $result->addLineItem('0018', "BMI Modifier (>35) (0018)", $bmiUnits, $timeUnitRate, $bmiUnits * $timeUnitRate);
            $result->addAmount($bmiUnits * $timeUnitRate);
            // $totalTimeUnits += $bmiUnits; // Should BMI affect subsequent reduction? Usually yes.
            // But let's verify if BMI units count towards "Reducible Bucket". Yes.
        }
        
        // Track current totals for bucket logic
        // We need to know what constitutes the "Reducible Bucket".
        // Usually: Time (Base + Emergency + BMI) + Non-Exempt Procedures.
        
        $reducibleTotal = ($baseTimeUnits + ($context->emergencyFlag ? 12.00 : 0) + ($bmi >= 35 ? ($baseTimeUnits + ($context->emergencyFlag ? 12.00 : 0)) * 0.5 : 0)) * $timeUnitRate;
        $exemptTotal = 0.0;

        // 3. Procedures
        $codeMap = $this->getModifierMetadata(array_column($context->procedures, 'code'));
        
        foreach ($context->procedures as $proc) {
            $code = $proc['code'];
            if ($code === '0023') continue;

            $msrList = $this->extractMsrList($proc);
            $msr = $msrList[0] ?? null;
            
            if (!$msr) continue;

            $unitPrice = $msr['tariffRatePublished'] ?? 0.0;
            $units = $msr['numberOfUnits'] ?? 0;
            $randValue = $msr['tariffRandCalculated'] ?? ($unitPrice * ($units > 0 ? $units : 1));
            
            // Add Line Item
            $result->addLineItem($code, $msr['description'] ?? "Procedure {$code}", $units, $unitPrice, $randValue);
            $result->addAmount($randValue);

            // Check Bucket
            $meta = $codeMap[$code] ?? [];
            $isExempt = !empty($meta['is_exempt_from_0036']);

            if ($isExempt) {
                $exemptTotal += $randValue;
            } else {
                $reducibleTotal += $randValue;
            }
        }

        // 4. Modifier 0036 (GP Reduction)
        $reductionAmount = 0.0;
        if ($durationMinutes > 60) {
            $reductionAmount = $reducibleTotal * 0.20; 
            if ($reductionAmount > 0) {
                $result->addLineItem('0036', "GP Reduction (Duration > 1hr)", 0, 0, -$reductionAmount);
                $result->addAmount(-$reductionAmount);
                $result->log("Modifier 0036 applied: -R" . number_format($reductionAmount, 2));
            }
        }

        // 6. PMB Check
        $isPmb = false;
        foreach ($context->diagnoses as $diag) {
            if ($this->isPmb($diag)) {
                $isPmb = true;
                $result->setIsPmb(true);
            }
        }
        
        // 7. PMB Multiplier
        if ($isPmb && $context->pmbRequestedRate > 1.0) {
             $currentTotal = ($reducibleTotal - $reductionAmount) + $exemptTotal;
             $newTotal = $currentTotal * $context->pmbRequestedRate;
             $extra = $newTotal - $currentTotal;
             
             if ($extra > 0) {
                 $result->addLineItem('PMB', "PMB Rate Adjustment (x{$context->pmbRequestedRate})", 0, 0, $extra);
                 $result->addAmount($extra);
             }
        }

        return $result;
    }

    private function calculateTimeUnits(int $minutes): float
    {
        if ($minutes <= 0) return 0.0;
        $units = 8.0; 
        if ($minutes > 60) {
            $extraMinutes = $minutes - 60;
            $blocks = ceil($extraMinutes / 15);
            $units += ($blocks * 3.0);
        }
        return $units;
    }

    private function calculateBMI(array $patient): float
    {
        $weight = $patient['weight_kg'] ?? 0;
        $heightCm = $patient['height_cm'] ?? 0;
        if ($weight <= 0 || $heightCm <= 0) return 0.0;
        $heightM = $heightCm / 100;
        return round($weight / ($heightM * $heightM), 1);
    }

    private function isPmb(string $code): bool
    {
        if ($this->medpraxService) {
            $icdData = $this->medpraxService->searchIcd10($code);
            if ($icdData) {
                if (isset($icdData->isPMB) && $icdData->isPMB) return true;
                if (isset($icdData->isPmb) && $icdData->isPmb) return true;
            }
        }
        try {
            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare("SELECT is_pmb FROM pmb_registry WHERE icd10_code = ?");
            $stmt->execute([$code]);
            $row = $stmt->fetch();
            if ($row) return (bool) $row['is_pmb'];
        } catch (\Exception $e) {}
        return in_array($code, ['D25.9', 'K35.8', 'O68.2', 'O82.0']); 
    }

    private function getModifierMetadata(array $codes): array
    {
        $map = [];
        if (empty($codes)) return $map;
        try {
            $pdo = $this->db->getConnection();
            $placeholders = str_repeat('?,', count($codes) - 1) . '?';
            $stmt = $pdo->prepare("SELECT * FROM modifier_metadata WHERE tariff_code IN ($placeholders)");
            $stmt->execute($codes);
            while ($row = $stmt->fetch()) {
                $map[$row['tariff_code']] = $row;
            }
        } catch (\Exception $e) {}
        $hardcodedExempt = ['0038', '0039', '1120', '1221', '2799'];
        foreach ($codes as $c) {
            if (!isset($map[$c]) && in_array($c, $hardcodedExempt)) {
                $map[$c] = ['is_exempt_from_0036' => 1];
            }
        }
        return $map;
    }
}
