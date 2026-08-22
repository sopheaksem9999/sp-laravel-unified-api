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

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

When the user types `/graphify`, use the installed graphify skill or instructions before doing anything else.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- Dirty graphify-out/ files are expected after hooks or incremental updates; dirty graph files are not a reason to skip graphify. Only skip graphify if the task is about stale or incorrect graph output, or the user explicitly says not to use it.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
