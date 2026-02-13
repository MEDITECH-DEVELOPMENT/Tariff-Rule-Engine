<?php

namespace Domain;

/**
 * Class CalculationContext
 *
 * simple DTO that holds the entire state of a tariff calculation request.
 * It strictly Types the incoming JSON data to ensure data integrity during the lifecycle.
 *
 * @package Domain
 */
class CalculationContext
{
    /**
     * @var string The discipline code (e.g., '014A').
     */
    public string $discipline;

    /**
     * @var string The role code (e.g., '03' for Anaesthetist).
     */
    public string $role;


    /**
     * @var array{start: string, end: string} Start and End times.
     */
    public array $times;

    /**
     * @var array{dob: string, weight_kg: float, height_cm: float} Patient demographics.
     */
    public array $patient;

    /**
     * @var bool Whether this is an emergency.
     */
    public bool $emergencyFlag;

    /**
     * @var string[] List of ICD-10 diagnosis codes.
     */
    public array $diagnoses;

    /**
     * @var array List of procedures with their associated MSRS prices.
     */
    public array $procedures;

    /**
     * @var float Requested PMB rate multiplier (e.g. 3.0). Default 1.0.
     */
    public float $pmbRequestedRate;

    /**
     * CalculationContext constructor.
     *
     * @param array $data Decoded JSON payload.
     */
    public function __construct(array $data)
    {
        $this->discipline = $data['discipline'] ?? '';
        $this->role = $data['role'] ?? '';
        $this->times = $data['times'] ?? ['start' => '00:00', 'end' => '00:00'];
        $this->patient = $data['patient'] ?? [];
        $this->emergencyFlag = $data['emergency_flag'] ?? false;
        $this->diagnoses = $data['diagnoses'] ?? [];
        $this->procedures = $data['procedures'] ?? [];
        $this->pmbRequestedRate = (float)($data['pmb_requested_rate'] ?? 1.0);
    }

    /**
     * Calculate duration in minutes associated with this context.
     *
     * @return int Total minutes between start and end time.
     */
    public function getDurationMinutes(): int
    {
        $start = \DateTime::createFromFormat('H:i', $this->times['start']);
        $end = \DateTime::createFromFormat('H:i', $this->times['end']);

        if (!$start || !$end) {
            return 0;
        }

        // Handle crossing midnight if needed, but for now simple diff
        $diff = $end->diff($start);
        return ($diff->h * 60) + $diff->i;
    }
}
