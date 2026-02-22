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
        $seq = 1;
        $ref = '87272'; // Placeholder
        $date = $this->serviceDate ?: date('Ymd');
        
        // T and Z Lines
        foreach ($this->lineItems as $item) {
            $code = $item['code'];
            $desc = $item['description'];
            $cents = (string)number_format($item['total'] * 100, 0, '', '');
            
            // T Line Logic: 
            // T|{Seq}|{Date}|{RefT}|02|100|06|{Code}|01|||01|{Desc}|Y||||||||{Ref}||21|
            // "100" = Quantity 1.00 - Standard for single procedure occurrence.
            // "06" = Standard Unit Value Type? (Using example literal '06')
            // "02" = Line Type? (Using '02')
            // "01" = Modifier Count? Or just '01'. Using example literal '01'.
            // "21" = Service Type? Using literal '21'.
            
            $tLine = sprintf(
                "T|%d|%s|%s|||%dT%s|02|100|06|%s|01|||01|%s|Y||||||||%s||21|",
                $seq, 
                $date, $date,
                $seq, $ref,
                $code,
                $desc,
                $ref
            );
            $lines[] = $tLine;

            // Z Line Logic: Z|{Total}|{Total}||||||||{Total}||||||{Total}||
            $zLine = sprintf(
                "Z|%s|%s||||||||%s||||||%s||",
                $cents, $cents, $cents, $cents
            );
            $lines[] = $zLine;

            $seq++;
        }
        return implode("\n", $lines);
    }
}
