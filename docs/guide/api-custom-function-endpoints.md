---
title: "Custom Function Endpoints"
description: "Custom global and table function configuration and endpoint usage."
keywords:
  - custom functions
  - table functions
  - global functions
  - function schemas
  - rpc custom logic
---

### Custom Functions

Custom functions (RPC endpoints) let you expose arbitrary logic under `/{api_prefix}/rpc/{name}` (global) or `/{api_prefix}/{table}/rpc/{name}` (table-scoped). They are configured via `RecordFunctionType` in `record.global_functions` or `RecordTableType::$functions`.

> **Tip — generate OpenAPI schemas for custom functions:**
> The package cannot auto-infer request/response shapes for custom functions. Use **[wk-tools.vercel.app/json-to-openapi](https://wk-tools.vercel.app/json-to-openapi)** to convert a sample JSON payload/response into an OpenAPI `schema` object, then attach it to `RecordFunctionType::$requestSchema` / `$responseSchema`. The exported schema and live `/docs/openapi.json` will include it automatically.

### Global Functions

```http
GET|POST|PUT|PATCH|DELETE /{api_prefix}/rpc/{functionName}
```

Execute global custom functions defined in `config/record.php`.

#### Examples

```http
# Simple global function
GET /api/v1/rpc/system_stats

# Parameterized global function
POST /api/v1/rpc/generate_report
Content-Type: application/json
{
  "report_type": "monthly",
  "date_range": "2024-01"
}
```

### Table Functions

```http
GET|POST|PUT|PATCH|DELETE /{api_prefix}/{table}/rpc/{functionName}
```

Execute table-specific custom functions.

#### Examples

```http
# Simple table function
GET /api/v1/invoices/rpc/calculate_totals

# Parameterized table function
POST /api/v1/users/rpc/send_notification
Content-Type: application/json
{
  "message": "Welcome to our platform!",
  "type": "welcome"
}
```

---

