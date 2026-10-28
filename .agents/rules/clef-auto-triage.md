# Clef Automatic Triage Rule

Use the `clef` skill (CLI `clef`) for fast, deterministic error triage and risk classification.

## Automatic Triggers

The agent MUST automatically invoke Clef triage whenever:
1. **Error / Exception Encountered**: The user pastes an error log, stack trace, 500 API response, or bug report.
2. **Build / Test Failure**: A command execution (`npm run type-check`, `npm run test`, `npm run build`, or API call) fails unexpectedly.
3. **Destructive / Risky Operations**: A task asks to drop tables, delete records in bulk, wipe cache, or run production deployments (`deploy:prod`).

## Actions on Trigger

When triggered, run `clef` or invoke the `clef` skill to determine:
- **Urgency / Severity**:
  - `P0`: Critical production outage, data loss, security exposure.
  - `P1`: Blocking build/type error, core feature broken.
  - `P2`: Non-blocking edge case, warning, UI styling defect.
  - `P3`: Minor chore, typo, cosmetic issue.
- **Root Cause Domain**:
  - `api_contract` (schema mismatch, bad endpoint/param)
  - `type_check` (TypeScript / vue-tsc error)
  - `dependency` (missing module, version conflict)
  - `auth_permission` (401, 403, missing token)
  - `runtime_logic` (null pointer, unhandled promise)
- **Triage Assessment**:
  - State the classified severity and domain concisely before jumping into the code fix.
