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
            'trace' => $this->trace,
        ];
    }
}
