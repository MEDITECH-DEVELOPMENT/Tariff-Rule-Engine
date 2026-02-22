<?php

namespace Domain;

/**
 * Class CalculationResult
 *
 * Represents the final output of the tariff calculation engine.
 *
 * @package Domain
 */
class CalculationResult
{
    private float $totalAmount = 0.0;
    private bool $isPmb = false;
    private array $trace = [];
    private array $lineItems = [];
    private string $serviceDate = '';
    private ?array $mainProcedure = null;
    private array $diagnoses = [];
    private string $doctorPcns = '';
    private string $doctorName = '';
    private string $transmissionRef = '';

    public function addAmount(float $amount): void
    {
        $this->totalAmount += $amount;
    }

    public function addLineItem(string $code, string $description, float $units, float $unitPrice, float $total): void
    {
        $this->lineItems[] = [
            'code' => $code,
            'description' => substr($description, 0, 70), // Truncate safety
            'units' => round($units, 2),
            'unit_price' => round($unitPrice, 2),
            'total' => round($total, 2)
        ];
    }

    public function setIsPmb(bool $isPmb): void
    {
        $this->isPmb = $isPmb;
    }
    
    public function setMainProcedure(?array $mainProcedure): void
    {
        $this->mainProcedure = $mainProcedure;
    }

    public function log(string $message): void { $this->trace[] = $message; }

    public function setServiceDate(string $date): void {
        $this->serviceDate = str_replace(['-', ':', ' '], '', $date);
        if (strlen($this->serviceDate) >= 8) {
            $this->serviceDate = substr($this->serviceDate, 0, 8); 
        } else {
            $this->serviceDate = date('Ymd');
        }
    }

    public function setDiagnoses(array $diagnoses): void {
        $this->diagnoses = $diagnoses;
    }

    public function setDoctorInfo(string $pcns, string $name): void {
        $this->doctorPcns = $pcns;
        $this->doctorName = $name;
    }

    public function setTransmissionRef(string $ref): void {
        $this->transmissionRef = $ref;
    }

    public function toArray(): array
    {
        $response = [
            'total_amount' => round($this->totalAmount, 2),
            'is_pmb' => $this->isPmb,
            'line_items' => $this->lineItems,
            'edi_payload' => $this->generateEdiPayload(),
            'trace' => $this->trace,
        ];
        
        if ($this->mainProcedure !== null) {
            $response['main_procedure'] = $this->mainProcedure;
        }
        
        return $response;
    }

    private function generateEdiPayload(): string
    {
        $lines = [];
        $ref = $this->transmissionRef ?: '87272';
        $date = $this->serviceDate ?: date('Ymd');
        $pcns = $this->doctorPcns ?: '0000000';
        $docName = $this->doctorName ?: 'UNKNOWN';
        
        // DR Record - Doctor (Anaesthetist)
        // DR|{PCNS}|{Name}|{Type}|{CMS Reg}|{CMS Type}||||
        // Type: 03 = Anaesthetist
        // CMS Type: 01 = HPCSA
        $lines[] = sprintf(
            "DR|%s|%s|03||01||||",
            $pcns,
            substr($docName, 0, 30)
        );
        
        // D Records - Diagnoses (ICD-10)
        // D|{Doctor Type}|{Code Type}|{Code}|{Description}|{Extended}|
        // Doctor Type: 01 = Attending
        // Code Type: 01 = ICD10
        // Extended: 01 = Primary, 02 = Secondary
        foreach ($this->diagnoses as $idx => $icd10) {
            $extended = ($idx === 0) ? '01' : '02'; // First is primary
            $lines[] = sprintf(
                "D|01|01|%s||%s|",
                $icd10,
                $extended
            );
        }
        
        // T and Z Records - Treatment Lines
        $seq = 1;
        $totalCents = 0;
        
        foreach ($this->lineItems as $item) {
            $code = $item['code'];
            $desc = substr($item['description'], 0, 70);
            $cents = (int)round($item['total'] * 100);
            $totalCents += $cents;
            
            // Determine treatment type and quantity
            // For modifiers (0xxx codes), use type 03
            // For tariffs, use type 02
            $treatmentType = (strlen($code) === 4 && $code[0] === '0') ? '03' : '02';
            
            // Quantity: 100 = 1.00 unit (2 decimal places implied)
            $quantity = (int)round($item['units'] * 100);
            
            // Unit type: 06 = Unit (default)
            $unitType = '06';
            
            // T Record
            // T|{Seq}|{Start}|{End}|{Auth}|{Script}|{LineRef}|{Type}|{Qty}|{UnitType}|{Code}|{CodeType}|{ModType}|{NAPPI}|{RateInd}|{Desc}|{PMB}|{ScriptDate}|{BenefitType}|{HospType}|{LabPCNS}|{LabRef}|{LabName}|{ResubCode}|{OrigClaim}|{OrigDate}|{PlaceOfService}|
            $lines[] = sprintf(
                "T|%d|%s|%s|||%dT%s|%s|%d|%s|%s|01|||01|%s|N||||||||%s||21|",
                $seq,
                $date,
                $date,
                $seq,
                $ref,
                $treatmentType,
                $quantity,
                $unitType,
                $code,
                $desc,
                $ref
            );
            
            // Z Record - Treatment Financial
            // Z|{Net}|{Gross}|{DispFee}|{ContFee}|{ExcessTime}|{CallOut}|{CopyFee}|{DelivFee}|{Contract}|{Claimed}|{Discount}|{Levy}|{MMAP}|{CoPay}|{PatLiable}|{FundLiable}|{MemberReimb}|
            $lines[] = sprintf(
                "Z|%d|%d||||||||%d||||||%d||",
                $cents,
                $cents,
                $cents,
                $cents
            );
            
            $seq++;
        }
        
        // F Record - Claim Financial Summary
        // F|{Net}|{Gross}|{Claimed}|{Discount}|{Levy}|{MMAP}|{CoPay}|{Receipt}|{PatLiable}|{FundLiable}|{MemberReimb}|
        $lines[] = sprintf(
            "F|%d|%d|%d|||||||%d||",
            $totalCents,
            $totalCents,
            $totalCents,
            $totalCents
        );
        
        // E Record - Footer
        // E|{TransmissionRef}|{ClaimCount}|{TotalValue}|
        $lines[] = sprintf(
            "E|%s|1|%d|",
            $ref,
            $totalCents
        );
        
        return implode("\n", $lines);
    }
}
