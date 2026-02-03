<?php

namespace Service;

use Database\Database;
use Domain\CalculationContext;
use Domain\DisciplineStrategy;
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
     * @var DisciplineStrategy[] List of registered strategies.
     */
    private array $strategies = [];

    /**
     * @var Database Database connection for auditing (and potentially for strategies).
     */
    private Database $db;

    /**
     * TariffCalculator constructor.
     *
     * @param Database $db Dependency injection of the database connection.
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Register a new strategy.
     *
     * @param DisciplineStrategy $strategy
     * @return void
     */
    public function registerStrategy(DisciplineStrategy $strategy): void
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
            // For now, we just don't crash the calculation.
            error_log("Audit Log Failed: " . $e->getMessage());
        }
    }
}
