-- Migration: 002_create_modifier_metadata.sql
-- Description: Stores metadata rules for specific tariff codes (e.g. exemptions from reductions).

CREATE TABLE IF NOT EXISTS `modifier_metadata` (
    `tariff_code` VARCHAR(10) NOT NULL COMMENT 'Primary Key: The tariff code (e.g., 0039, 1221).',
    `is_exempt_from_0036` BOOLEAN DEFAULT FALSE COMMENT 'If true, this code is NOT subject to GP Reduction rules (Mod 0036).',
    `category` ENUM('reducible', 'exempt', 'add_on') DEFAULT 'reducible' COMMENT 'Classification of the code for bucket logic.',
    PRIMARY KEY (`tariff_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Metadata rules for tariff codes regarding modifier application.';
