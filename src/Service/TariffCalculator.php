<?php

namespace Service;

use Database\Database;
use Domain\CalculationContext;
use Domain\DisciplineStrategy as StrategyInterface;
use Domain\Strategies\AnaestheticStrategy014A;
use RuntimeException;

/**
 * Class TariffCalculator
 *
 * Orchestrates the tariff calculation process. It acts as the pipeline handler,
 * accepting a request, validating it, selecting the appropriate strategy,
 * and returning the formatted response.
 *
 * @package Service
 */
class TariffCalculator
{
    /**
     * @var StrategyInterface[] List of registered strategies.
     */
    private array $strategies = [];

    /**
     * @var Database Database connection for auditing (and potentially for strategies).
     */
    private Database $db;

    /**
     * @var MedpraxService Service for fetching tariff prices.
     */
    private MedpraxService $medpraxService;

    /**
     * TariffCalculator constructor.
     *
     * @param Database $db Dependency injection of the database connection.
     * @param MedpraxService $medpraxService Service for MSR lookups.
     */
    public function __construct(Database $db, MedpraxService $medpraxService)
    {
        $this->db = $db;
        $this->medpraxService = $medpraxService;

        // Auto-Register Core Strategy for demo purposes
        // Note: In real app, DI container handles this registration
        $this->registerStrategy(new AnaestheticStrategy014A($db, $medpraxService));
    }

    /**
     * Register a new strategy.
     *
     * @param StrategyInterface $strategy
     * @return void
     */
    public function registerStrategy(StrategyInterface $strategy): void
    {
        $this->strategies[] = $strategy;
    }

    /**
     * Process a calculation request.
     *
     * @param array $requestPayload The raw JSON decoded array.
     * @return array The formatted response array.
     * @throws RuntimeException If no suitable strategy is found.
     */
    public function calculate(array $requestPayload): array
    {
        // Hydrate prices if missing
        $requestPayload = $this->hydratePrices($requestPayload);

        $context = new CalculationContext($requestPayload);

        // Stage 1: Classifier (Find Strategy)
        $selectedStrategy = null;
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($context)) {
                $selectedStrategy = $strategy;
                break;
            }
        }

        if (!$selectedStrategy) {
            throw new RuntimeException("No strategy found for Discipline: {$context->discipline}");
        }

        // Execute Strategy
        $result = $selectedStrategy->calculate($context);
        $response = $result->toArray();

        // Log the transaction (Fire and forget, or sync)
        $this->auditLog($requestPayload, $response);

        return $response;
    }

    /**
     * Fetch MSRs for procedures if they are missing in the payload.
     *
     * @param array $payload
     * @return array
     */
    private function hydratePrices(array $payload): array
    {
        $procedures = $payload['procedures'] ?? [];
        if (empty($procedures)) {
            return $payload;
        }

        $codesToLookup = [];
        $procedureIndices = []; 

        foreach ($procedures as $idx => $proc) {
            // Check if Msrs is empty or invalid
            $hasValidMsr = !empty($proc['msrs']) && (isset($proc['msrs']['pageResult']) || is_array($proc['msrs']));
            
            if (!$hasValidMsr) {
                $codesToLookup[] = $proc['code'] ?? null;
                if (isset($proc['code'])) {
                    $procedureIndices[$proc['code']][] = $idx;
                }
            }
        }

        $codesToLookup = array_values(array_filter(array_unique($codesToLookup)));

        if (empty($codesToLookup)) {
            return $payload;
        }

        $discipline = $payload['discipline'] ?? '014A';
        $planOption = $payload['plan_option'] ?? '39I';
        $serviceDate = $payload['service_date'] ?? null;

        try {
            $result = $this->medpraxService->getTariffMsr('medical', $codesToLookup, $planOption, $discipline, $serviceDate);
            
            if ($result && isset($result->msrs->pageResult)) {
                foreach ($result->msrs->pageResult as $msrItem) {
                    $code = $msrItem->tariffCode->code ?? null;
                    if ($code && isset($procedureIndices[$code])) {
                        // Apply this MSR to all procedure entries with this code
                        foreach ($procedureIndices[$code] as $procIdx) {
                            $payload['procedures'][$procIdx]['msrs'] = [
                                'pageResult' => [$msrItem]
                            ];
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("TariffCalculator Warning: Failed to hydrate prices: " . $e->getMessage());
        }

        return $payload;
    }

    /**
     * Save the calculation trace to the database.
     *
     * @param array $request
     * @param array $response
     * @return void
     */
    private function auditLog(array $request, array $response): void
    {
        try {
            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare("
                INSERT INTO calculation_logs (id, request_payload, response_payload, trace_log, created_at)
                VALUES (UUID(), :request, :response, :trace, NOW())
            ");

            $stmt->execute([
                ':request' => json_encode($request),
                ':response' => json_encode($response),
                ':trace' => json_encode($response['trace'] ?? []),
            ]);
        } catch (\Exception $e) {
            // Silently fail logging in production, or handle error.
            error_log("Audit Log Failed: " . $e->getMessage());
        }
    }
}
