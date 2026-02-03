<?php

namespace Domain;

/**
 * Interface DisciplineStrategy
 *
 * Defines the contract for discipline-specific tariff calculations.
 * Each discipline (e.g., 014A, 020, 004) will have its own implementation of this interface.
 *
 * @package Domain
 */
interface DisciplineStrategy
{
    /**
     * Check if this strategy supports the given context.
     *
     * @param CalculationContext $context
     * @return bool
     */
    public function supports(CalculationContext $context): bool;

    /**
     * Execute the calculation logic.
     *
     * @param CalculationContext $context
     * @return CalculationResult
     */
    public function calculate(CalculationContext $context): CalculationResult;
}
