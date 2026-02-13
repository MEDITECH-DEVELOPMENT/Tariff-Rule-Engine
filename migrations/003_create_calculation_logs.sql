-- Migration: 003_create_calculation_logs.sql
-- Description: Audit log for all calculations performed by the engine.

CREATE TABLE IF NOT EXISTS `calculation_logs` (
    `id` CHAR(36) NOT NULL COMMENT 'UUID of the log entry.',
    `request_payload` JSON NOT NULL COMMENT 'Full JSON request received from the client.',
    `response_payload` JSON NOT NULL COMMENT 'Full JSON response sent to the client.',
    `trace_log` JSON DEFAULT NULL COMMENT 'Extracted trace steps for debugging.',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp of the calculation.',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit logs for tariff calculations.';
