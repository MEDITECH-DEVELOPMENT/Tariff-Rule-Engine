-- Migration: 001_create_pmb_registry.sql
-- Description: Creates the registry table for Prescribed Minimum Benefits (PMB).

CREATE TABLE IF NOT EXISTS `pmb_registry` (
    `icd10_code` VARCHAR(10) NOT NULL COMMENT 'Primary Key: The ICD-10 diagnosis code.',
    `description` TEXT NOT NULL COMMENT 'Description of the diagnosis.',
    `is_pmb` BOOLEAN DEFAULT TRUE COMMENT 'Flag indicating if this diagnosis is a PMB.',
    PRIMARY KEY (`icd10_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registry of PMB conditions for tariff calculation alerts.';
