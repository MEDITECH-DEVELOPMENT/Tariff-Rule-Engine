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
    /**
     * @var float Total calculated amount in Rand.
     */
    private float $totalAmount = 0.0;

    /**
     * @var bool Detailed flag if any diagnosis is PMB.
     */
    private bool $isPmb = false;

    /**
     * @var string[] Audit trace of calculation steps.
     */
    private array $trace = [];

    /**
     * @var array[] List of line items contributing to the total.
     */
    private array $lineItems = [];

    /**
     * Add an amount to the total.
     *
     * @param float $amount
     * @return void
     */
    public function addAmount(float $amount): void
    {
        $this->totalAmount += $amount;
    }

    /**
     * Add a structured line item.
     * 
     * @param string $code
     * @param string $description
     * @param float $units
     * @param float $unitPrice
     * @param float $total
     */
    public function addLineItem(string $code, string $description, float $units, float $unitPrice, float $total): void
    {
        $this->lineItems[] = [
            'code' => $code, // e.g. '0023' or '2615'
            'description' => $description,
            'units' => round($units, 2),
            'unit_price' => round($unitPrice, 2),
            'total' => round($total, 2)
        ];
    }

    /**
     * Set the PMB status.
     *
     * @param bool $isPmb
     * @return void
     */
    public function setIsPmb(bool $isPmb): void
    {
        $this->isPmb = $isPmb;
    }

    /**
     * Add a log entry to the trace.
     *
     * @param string $message
     * @return void
     */
    public function log(string $message): void
    {
        $this->trace[] = $message;
    }

    /**
     * Convert the result to an array for JSON response.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'total_amount' => round($this->totalAmount, 2),
            'is_pmb' => $this->isPmb,
            'line_items' => $this->lineItems,
            'trace' => $this->trace,
        ];
    }
}
