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
        
        // Set EDI metadata
        $result->setServiceDate($context->serviceDate);
        $result->setDiagnoses($context->diagnoses);
        
        // Set doctor info from context or use fallback
        $doctorPcns = $context->doctor['pcns'] ?? '0000000';
        $doctorName = $context->doctor['name'] ?? 'ANAESTHETIST';
        $result->setDoctorInfo($doctorPcns, $doctorName);
        $result->setTransmissionRef('TXNUM'); // PMA will update this at submission time
        
        // Log main procedure context if provided
        if ($context->mainProcedure) {
            $mainCode = $context->mainProcedure['code'] ?? 'Unknown';
            $mainDesc = $context->mainProcedure['description'] ?? '';
            $result->log("Main Surgical Procedure: {$mainCode}" . ($mainDesc ? " - {$mainDesc}" : ""));
            $result->setMainProcedure($context->mainProcedure);
        }

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
        
        // Modifier 0011 (Emergency) - Add to time units if applicable
        $emergUnits = 0.00;
        $modifiers = [];
        if ($context->emergencyFlag) {
            $emergUnits = 12.00;
            $totalTimeUnits += $emergUnits;
            $modifiers[] = ['code' => '0011', 'type' => '03']; // Type 03 = Add Modifier
        }
        
        // Add 0023 line item with emergency modifier attached if applicable
        $timeDescription = $context->emergencyFlag 
            ? "Time Units (Base: {$durationMinutes} min + Emergency: 12 units)"
            : "Time Units (Base: {$durationMinutes} min)";
        
        $result->addLineItem('0023', $timeDescription, $totalTimeUnits, $timeUnitRate, $totalTimeUnits * $timeUnitRate, $modifiers);
        $result->addAmount($totalTimeUnits * $timeUnitRate);

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

        // 3. Procedures (Filter out auto-calculated modifiers)
        $autoModifiers = ['0023', '0036', '0011', '0018']; // Auto-calculated, should not be in procedures
        $validProcedures = array_filter($context->procedures, function($proc) use ($autoModifiers) {
            return !in_array($proc['code'] ?? '', $autoModifiers);
        });
        
        $codeMap = $this->getModifierMetadata(array_column($validProcedures, 'code'));
        
        foreach ($validProcedures as $proc) {
            $code = $proc['code'];

            $msrList = $this->extractMsrList($proc);
            $msr = $msrList[0] ?? null;
            
            if (!$msr) continue;

            $unitPrice = $msr['tariffRatePublished'] ?? 0.0;
            $units = $msr['numberOfUnits'] ?? 0;
            $randValue = $msr['tariffRandCalculated'] ?? ($unitPrice * ($units > 0 ? $units : 1));
            
            // Skip procedures with 0 total (invalid for EDI)
            if ($randValue == 0 && $units == 0) {
                $result->log("Skipping procedure {$code} - zero value");
                continue;
            }
            
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
        // Note: 0036 should not be sent as a separate line item in EDI
        // The reduction is applied to the total amount only
        $reductionAmount = 0.0;
        if ($durationMinutes > 60) {
            $reductionAmount = $reducibleTotal * 0.20; 
            if ($reductionAmount > 0) {
                // Apply reduction to total without creating a line item
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
        // Note: PMB rate multiplier is applied to the total amount, not as a separate line item
        // The PMA should handle PMB rate adjustments in the financial records, not as a tariff code
        if ($isPmb && $context->pmbRequestedRate > 1.0) {
             $currentTotal = ($reducibleTotal - $reductionAmount) + $exemptTotal;
             $newTotal = $currentTotal * $context->pmbRequestedRate;
             $extra = $newTotal - $currentTotal;
             
             if ($extra > 0) {
                 // Apply PMB adjustment to total amount only
                 $result->addAmount($extra);
                 $result->log("PMB Rate Adjustment (x{$context->pmbRequestedRate}): +R" . number_format($extra, 2));
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
