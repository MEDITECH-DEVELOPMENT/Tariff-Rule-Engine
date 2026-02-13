ARCHITECTURE.md: Medical Tariff Calculation Engine
1. Executive Summary

A standalone PHP API designed to calculate medical professional fees according to South African billing standards. The engine uses a Strategy Pattern to handle discipline-specific rules and a Pipeline Pattern to process modifiers.

Core Pricing Philosophy

JSON-First (Primary): The engine must use the msrs array within the incoming request to find units and rates.

SAMA-Sensitive Logic: While using Scheme prices, the engine must apply SAMA-style logic (e.g., 15-minute time rounding, GP reductions, and Bucket-based modifier application).

PMB Awareness: If a diagnosis is a Prescribed Minimum Benefit (PMB), the engine flags it for potential SAMA-rate billing in the future.

2. Technical Stack

Language: PHP 8.2+ (Containerized)

Framework: (Lightweight) Slim, Lumen, or Native PHP with Composer.

Database: MySQL 8.0 (Managed via migrations).

Environment: Docker & Docker Compose.

Communication: JSON REST API.



3. Database Schema (MySQL)
pmb_registry

Used to identify if a claim qualifies for PMB status.

icd10_code (VARCHAR 10, Primary Key)

description (TEXT)

is_pmb (BOOLEAN, Default True)

modifier_metadata

Classifies how specific codes interact with global rules.

tariff_code (VARCHAR 10, Primary Key)

is_exempt_from_0036 (BOOLEAN): True for codes like 0039, 1221, 1120.

category (ENUM: 'reducible', 'exempt', 'add_on')

calculation_logs

Stores traces for audit and debugging.

id (UUID)

request_payload (JSON)

response_payload (JSON)

trace_log (JSON)

created_at (TIMESTAMP)

4. The Calculation Pipeline
Stage 1: Classifier

Determine the Strategy based on discipline (e.g., 014A) and role (e.g., 03 - Anaesthetist).

Stage 2: Time Engine (Modifier 0023)

Mandatory for Anaesthetic roles. Do not use JSON units for code 0023.

Calculate total_minutes (End Time - Start Time).

First 60 mins: 8.0 Units.

After 60 mins: Calculate 15-minute blocks (or part thereof, rounded up).

Unit Value: Each block = 3.0 Units.

Formula: TotalUnits = 8 + (CEIL((total_minutes - 60) / 15) * 3).

Stage 3: Bucket Allocation

Group all incoming procedure codes from the request:

Reducible Bucket: Basic Units + Calculated Time Units (0023).

Exempt Bucket: Procedures where is_exempt_from_0036 is TRUE.

Stage 4: Modifier Execution

BMI Modifier (0018):

Calculation: Height / (Weight^2).

If BMI >= 35: Increase Time Units (0023) by 50% before reduction.

GP Reduction Rule (0036):

Condition: Discipline == 014A AND total_minutes > 60.

Action: Multiply total units in Reducible Bucket by 0.8.

Emergency Modifier (0011):

If emergency_flag == true, add fixed units based on Modifier 0011 rules.

5. Discipline-Specific Logic: 014A (GP Anaesthetics)
Rule	Application
Price Selection	Use tariffRatePublished from the msrs JSON in the request.
Time Units (0023)	Use the Time Engine logic (Stage 2).
GP Reduction (0036)	Apply 20% discount (0.8 multiplier) to procedure basic units and time units if surgery > 1 hour.
Add-on Exemption	Codes 0038, 0039, 1120, 1221, 2799 must be billed at 100% (No reduction).
6. API Input/Output Contract
Input Request
code
JSON
download
content_copy
expand_less
{
  "discipline": "014A",
  "role": "03",
  "times": { "start": "07:53", "end": "09:45" },
  "patient": { "dob": "1985-01-01", "weight_kg": 109, "height_cm": 170 },
  "emergency_flag": false,
  "diagnoses": ["D25.9"],
  "procedures": [
    { "code": "2471", "msrs": [{ "priceGroupCode": "MSR24", "numberOfUnits": 6, "tariffRatePublished": 126.74 }] },
    { "code": "1221", "msrs": [{ "priceGroupCode": "MSR24", "numberOfUnits": 30, "tariffRatePublished": 20.19 }] }
  ]
}
Output Response
code
JSON
download
content_copy
expand_less
{
  "total_amount": 11682.03,
  "is_pmb": true,
  "trace": [
    "Duration: 112 mins. Base Time Units: 20.00.",
    "BMI 37.7 detected. Modifier 0018: +10.00 units added to Time.",
    "Modifier 0036 applied: Reducible bucket (2471 + 0023) multiplied by 0.8.",
    "Exempt code 1221 identified: Billed at 100% of JSON units.",
    "Diagnosis D25.9 identified as PMB: Alert triggered."
  ]
}
7. Development Roadmap
Phase 1: The JSON Engine (Current)

Implement MySQL Migrations for pmb_registry and modifier_metadata.

Build the AnaestheticStrategy logic.

Ensure 100% reliance on msrs JSON for base prices.

Implement the "Trace" logger for debugging.

Phase 2: SAMA Integration (Future)

Add sama_units to the tariff_master table.

Implement a pricing_mode toggle (SCHEME vs SAMA).

Add Rand Conversion Factors (RCF) table to MySQL.

8. Antigravity Instructions

"Please implement this architecture using PHP 8.2. Begin by creating the MySQL migrations. Ensure that all unit calculations for Discipline 014A follow the 'Bucket System' described in Section 4. Use the provided JSON msrs as the only source of price data. Every response must contain a detailed trace array. Add as much documentations in the code as possible."