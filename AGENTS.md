# Agent Instructions

This file is the entrypoint. The single source of truth for project context lives in `.agents/rules/` — read these before working:

- `.agents/rules/project-context.md` — overview, stack, repo map
- `.agents/rules/architecture.md` — config-driven CRUD, MCP, exporters
- `.agents/rules/commands.md` — test / analyse / format / artisan commands
- `.agents/rules/coding-standards.md` — conventions, testing, tooling quirks

## Working Rules
- Follow existing patterns in the repo; prefer minimal changes and small diffs.
- Run the relevant command from `.agents/rules/commands.md` after changes.
- Maintain backward compatibility unless explicitly asked to break.
- Do not add secrets, tokens, or private keys to code or logs.
- If unsure about architecture or product requirements, ask before implementing.
- For module/feature context, read `docs/guide/*` first (saves tokens), then open `src/` only for implementation detail.
- When changing core behaviour or adding a module/feature, update the matching `docs/guide/*` page (see `.agents/rules/coding-standards.md`).
