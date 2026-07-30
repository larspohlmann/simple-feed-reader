# Agent instructions

This project's conventions, commands, architecture, and coding standards are documented in [`CLAUDE.md`](CLAUDE.md) in the repository root. All agents **must** read and follow it before performing any work.

## Quick reference

- **Backend:** Symfony 7.4 / PHP 8.4 — run `composer check`, `php bin/phpunit` from `backend/`
- **Frontend:** Angular 20 — run `npm run check`, `npm test` from `frontend/`
- **Docker:** `docker compose up -d` from root
- **Git flow:** feature branches off `develop`, PRs merge to `develop`
- **PHP code style:** Clean Code — see CLAUDE.md §PHP code style for non-negotiables
- **Frontend conventions:** standalone components, signals, no NgModules
- **Tests:** SQLite natively, MySQL in Docker; run both before PR

## Claude project config

- `.claude/settings.local.json` — local tool permissions (MCP, SSH, shell commands)
- `.claude/worktrees/` — active git-worktree sessions (do not modify manually)
- `CLAUDE.md` — full project conventions, architecture, and coding standards

## Claude memory files

Project-specific memories live under `~/.claude/projects/<project-hash>/memory/`.
The entry point is `MEMORY.md` which links to individual decision, gotcha, workflow, and domain-knowledge files.

Global memories (applied across all projects) live under `~/.claude/memory/`.

**Your per-machine path to the project memory is in [`AGENTS.local.md`](AGENTS.local.md) — read it first.**
**All agents must read the project memory index (`MEMORY.md` and its linked files) before performing any work — these capture non-negotiable project decisions that may override or supplement `CLAUDE.md`.**
