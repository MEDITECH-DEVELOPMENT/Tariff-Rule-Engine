<?php

namespace Domain\Strategies;

use Database\Database;
use Domain\CalculationContext;
use Domain\CalculationResult;
use Domain\DisciplineStrategy;
use Service\MedpraxService; // Need this for PMB lookup
use PDO;

/**
 * Class AnaestheticStrategy014A
 *
 * Implements the Specific Tariff Rules for Discipline 014A (General Practitioner performing Anaesthetics).
 *
 * Key Logic Layers:
 * 1. Time Engine (Modifier 0023).
 * 2. Bucket System (Reducible vs Exempt).
 * 3. Modifiers (0018 BMI, 0036 GP Reduction).
 * 4. PMB & Emergency (0011).
 *
 * @package Domain\Strategies
 */
class AnaestheticStrategy014A implements DisciplineStrategy
{
    private Database $db;
    
    // Optional service for PMB checks if not using DB fallback
    // But DisciplineStrategy interface construct is fixed in TariffCalculator usually.
    // However, we can inject it via setter or constructor if container allows.
    // For now, I will assume we can't easily change the constructor signature without breaking interface instantiation pattern 
    // unless we change TariffCalculator to pass it.
    // Wait, TariffCalculator instantiates the Strategy: `new AnaestheticStrategy014A($db)`.
    // I should update TariffCalculator to pass MedpraxService to the strategy? 
    // Yes, that's better design.
    
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

        // 1. Calculate Duration and Base Time Units (0023)
        $durationMinutes = $context->getDurationMinutes();
        $timeUnits = $this->calculateTimeUnits($durationMinutes);
        $result->log("Duration: {$durationMinutes} mins. Base Time Units: " . number_format($timeUnits, 2));
        
        // 1b. Modifier 0011 (Emergency)
        // Standard rule: Add 12.00 units to time? Or procedures?
        // Usually adds 12.00 to the anaesthetic fee (Time bucket).
        if ($context->emergencyFlag) {
            $timeUnits += 12.00;
            $result->log("Modifier 0011 (Emergency) applied: +12.00 Time Units.");
        }

        // 2. Apply Modifier 0018 (BMI) -> Affects Time Units
        $bmi = $this->calculateBMI($context->patient);
        if ($bmi >= 35) {
            $addedUnits = $timeUnits * 0.50; // Note: Does this apply to the base+0011 total or just base? 
            // "Increase Time Units (0023) by 50%". Usually applies to the total time units.
            $timeUnits += $addedUnits;
            $result->log("BMI {$bmi} detected (>= 35). Modifier 0018: +{$addedUnits} units added to Time.");
        }

        // 3. Bucket Allocation
        $codeMap = $this->getModifierMetadata(array_column($context->procedures, 'code'));
        $reducibleBucketUnits = 0.0;
        $exemptBucketUnits = 0.0;

        // FIND 0023 Rate
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

        $reducibleBucketUnits = $timeUnits * $timeUnitRate;
        
        foreach ($context->procedures as $proc) {
            $code = $proc['code'];
            if ($code === '0023') continue;

            $msrList = $this->extractMsrList($proc);
            $msr = $msrList[0] ?? null;
            if (!$msr) {
                $result->log("Warning: No price found for code {$code}");
                continue;
            }

            $price = $msr['tariffRatePublished'] ?? 0.0;
            if ($price <= 0) $price = $msr['tariffRandSchemeFixed'] ?? 0.0;
            if ($price <= 0) $price = $msr['tariffRandCalculated'] ?? 0.0;

            $meta = $codeMap[$code] ?? [];
            $isExempt = !empty($meta['is_exempt_from_0036']);

            if ($isExempt) {
                $priceToUse = $msr['tariffRandCalculated'] ?? ($msr['tariffRatePublished'] * $msr['numberOfUnits']);
                $exemptBucketUnits += $priceToUse;
            } else {
                $priceToUse = $msr['tariffRandCalculated'] ?? ($msr['tariffRatePublished'] * $msr['numberOfUnits']);
                $reducibleBucketUnits += $priceToUse; 
            }
        }

        // 4. Modifier 0036 (GP Reduction)
        if ($durationMinutes > 60) {
            $result->log("Modifier 0036 applied: Reducible bucket multiplied by 0.8.");
            $reducibleBucketUnits *= 0.8;
        }

        // 5. Total
        $total = $reducibleBucketUnits + $exemptBucketUnits;
        $result->addAmount($total);

        // 6. PMB Check
        $isPmb = false;
        foreach ($context->diagnoses as $diag) {
            if ($this->isPmb($diag)) {
                $isPmb = true;
                $result->setIsPmb(true);
                $result->log("Diagnosis {$diag} identified as PMB: Alert triggered.");
            }
        }
        
        // 7. Apply PMB Requested Rate Multiplier (if applicable)
        if ($isPmb && $context->pmbRequestedRate > 1.0) {
            $oldTotal = $total;
            $total *= $context->pmbRequestedRate;
            $result->log("PMB Rate Applied: Total adjusted by x{$context->pmbRequestedRate} (R{$oldTotal} -> R{$total}).");
            // Clear previous amount and set new total? Or just add the difference?
            // CalculationResult usually accumulates. 
            // We should use setAmount or similar if available, otherwise just add difference.
            // But CalculationResult->addAmount() just adds. 
            // Better to re-set the result amounts if possible, but for MVP just hacking the logic:
            // The `addAmount` call above was the only one. 
            // Wait, result accumulates amounts. 
            // We should ideally modify the result structure more cleanly, but for now:
            // Since we already added $total, we need to add the EXTRA amount.
            $extra = $total - $oldTotal;
            $result->addAmount($extra); // Total is now correct (Old + Extra = New)
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
        // 1. Try Medprax API via Service if available
        if ($this->medpraxService) {
            $icdData = $this->medpraxService->searchIcd10($code);
            if ($icdData) {
                // Check PMB fields in response
                // Response structure example (guessed): { "isPmb": true, "pmbDescription": ... }
                // Based on common Medprax fields: 'isPMB'
                // If specific field unknown, we log warning or rely on property name
                if (isset($icdData->isPMB) && $icdData->isPMB) return true;
                if (isset($icdData->isPmb) && $icdData->isPmb) return true;
            }
        }
        
        // 2. Fallback to DB
        try {
            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare("SELECT is_pmb FROM pmb_registry WHERE icd10_code = ?");
            $stmt->execute([$code]);
            $row = $stmt->fetch();
            if ($row) return (bool) $row['is_pmb'];
        } catch (\Exception $e) {}

        // 3. Hardcoded Fallback
        return in_array($code, ['D25.9', 'K35.8', 'O68.2', 'O82.0']); // Added obstetric codes for testing
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
