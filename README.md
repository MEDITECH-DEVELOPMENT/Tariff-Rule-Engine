# Medical Tariff Calculation Engine

A PHP-based API for calculating medical professional fees (specifically Discipline 014A - GP Anaesthetics) using a Strategy Pattern and Modifier Bucket System.

## Architecture
*   **Language**: PHP 8.2
*   **Database**: MySQL 8.0
*   **Environment**: Docker & Docker Compose
*   **Pattern**: Strategy Design Pattern for discipline-specific logic.

## Prerequisites
*   Docker & Docker Compose

## Deployment & Setup

1.  **Clone the repository** (if not already done).
2.  **Start the environment**:
    ```bash
    docker compose up -d --build
    ```
    This starts Nginx (port 8080), PHP-FPM, and MySQL.

## Usage

### API Endpoint
The engine exposes a single endpoint at `POST /`.

**URL**: `http://localhost:8080/`

**Example Request:**
```json
{
  "discipline": "014A",
  "role": "03",
  "times": { "start": "07:53", "end": "09:45" },
  "patient": { "dob": "1985-01-01", "weight_kg": 109, "height_cm": 170 },
  "emergency_flag": false,
  "diagnoses": ["D25.9"],
  "procedures": [
    { "code": "2471", "msrs": [{ "priceGroupCode": "MSR24", "numberOfUnits": 6, "tariffRatePublished": 126.74 }] }
  ]
}
```

## Testing / Verification

To run the internal verification script (which mocks a complex scenario):

```bash
docker compose run --rm app php tests/verify.php
```


## Database Migrations

This project includes a custom migration runner. To apply calculations:

1.  **Ensure Docker is running**.
2.  **Run the migration command**:
    ```bash
    docker compose run --rm app composer migrate
    ```

This will create the `pmb_registry`, `modifier_metadata`, and `calculation_logs` tables.

