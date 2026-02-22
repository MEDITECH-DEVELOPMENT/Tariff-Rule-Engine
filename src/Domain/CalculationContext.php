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
    public string $discipline;
    public string $role;
    
    /**
     * @var string The service date (YYYY-MM-DD).
     */
    public string $serviceDate;

    public array $times;
    public array $patient;
    public bool $emergencyFlag;
    public array $diagnoses;
    public array $procedures;
    public float $pmbRequestedRate;
    
    /**
     * @var array|null The main surgical procedure that the anaesthetic service supports.
     */
    public ?array $mainProcedure;

    public function __construct(array $data)
    {
        $this->discipline = $data['discipline'] ?? '';
        $this->role = $data['role'] ?? '';
        $this->serviceDate = $data['service_date'] ?? date('Y-m-d');
        $this->times = $data['times'] ?? ['start' => '00:00', 'end' => '00:00'];
        $this->patient = $data['patient'] ?? [];
        $this->emergencyFlag = $data['emergency_flag'] ?? false;
        $this->diagnoses = $data['diagnoses'] ?? [];
        $this->procedures = $data['procedures'] ?? [];
        $this->pmbRequestedRate = (float)($data['pmb_requested_rate'] ?? 1.0);
        $this->mainProcedure = $data['main_procedure'] ?? null;
    }
    
    public function getDurationMinutes(): int
    {
        // Simple diff logic
        $start = \DateTime::createFromFormat('H:i', $this->times['start'] ?? '00:00');
        $end = \DateTime::createFromFormat('H:i', $this->times['end'] ?? '00:00');
        
        if (!$start || !$end) return 0;
        
        $diff = ($end->getTimestamp() - $start->getTimestamp()) / 60;
        if ($diff < 0) $diff += 1440; // Wrap around midnight
        
        return (int) $diff;
    }
}
