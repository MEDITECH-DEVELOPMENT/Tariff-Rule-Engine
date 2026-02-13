# Future Sprint: Calculation Engine Enhancements

## 1. Architectural Strategy: Batch Processing vs. Line-by-Line

### Current State
The engine currently accepts a full payload (context) but returns a simplified response (Total Amount + Trace Log).

### Recommendation: "Batch Input, Granular Output"
For Medical Billing, especially Anaesthetics (014A), **Batch Processing** is mandatory due to:
1.  **Global Modifiers (e.g., 0036)**: Rules that apply percentage reductions based on total duration or total unit value cannot be calculated on a per-line basis.
2.  **Cross-Code Logic**: Mutual exclusivity and add-on codes require awareness of the entire procedure list.
3.  **Time Units**: Are derived from the case duration, affecting the global "Reducible Bucket".

### Proposed Result Structure
Instead of a single total, the API should return a breakdown per line item to assist with remittance and transparency:

```json
{
    "total_amount": 11682.03,
    "summary": { "duration_mins": 112, "modifiers_applied": ["0018", "0036"] },
    "line_items": [
        {
            "code": "0023",
            "description": "Time Units",
            "units": 30,
            "unit_price": 22.50,
            "gross_amount": 675.00,
            "net_amount": 540.00, // Reduced by 0036
            "status": "REDUCIBLE_APPLIED"
        },
        {
            "code": "1221",
            "gross_amount": 20.19,
            "net_amount": 20.19, // Exempt
            "status": "EXEMPT"
        }
    ]
}
```

## 2. API Integration: Direct Access to MSR Data

### Current Limitation
The engine currently relies on the caller (Client) to fetch MSR prices and inject them into the payload. This leads to:
*   Large payloads.
*   Potential for stale or manipulated pricing data.
*   Risk of missing "System Rates" (like Anaesthetic RCF).

### Proposal: Server-Side MSR Lookup
The Calculation Engine should have direct access to the Master Tariff API (or Database) to fetch prices itself.

**Workflow:**
1.  **Input**: Client sends `{"discipline": "014A", "procedures": ["2471", "1221"]}` (No prices).
2.  **Lookup**: Engine queries the MSR Source of Truth for the latest valid prices for the given date.
3.  **Calculation**: Engine applies rules using trusted system data.

**Benefits:**
*   Guaranteed accuracy of RCF and Unit Rates.
*   Smaller request payloads.
*   Centralized management of annual tariff updates.
