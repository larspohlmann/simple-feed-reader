# Folders by concern — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans (inline; one commit per task). Steps use checkbox (`- [ ]`) syntax.

**Goal:** `reader/`, `core/`, `settings/`, `admin/` and `reader/magazine/` stop being flat. In each folder the root keeps only what defines the feature (routes, api, models, page/shell) plus modules that several of its subfolders share. Everything else moves into a folder named after a concern, never after a type (#1314).

**Architecture:** This is a pure move with no code changes.
- A throwaway node script `git mv`s the files.
- It then rewrites every relative string literal in `src/` and `e2e/` that points at a moved file, or sits in a moved file, and prints anything it could not resolve.
- Build, lint (the boundaries rule included) and Jest prove the result.

**Tech Stack:** Angular 20, ESLint boundaries, Jest in the frontend container.

**Out of scope (lean):**
- `auth/`, `setup/`, `shared/`, `discover/`, `theme/` and `settings/admin/` are already grouped by concern.
- `reader/media-embeds.ts` and `embed-frame-allowlist.generated.json` stay in `reader/`, because `DumpEmbedFrameAllowlistCommand` and the php container's `docker-compose.yml` mount use that path.
- No renames of files or symbols.

## Global Constraints

- Starts after #1304 has merged. Branch `refactor/1314-folders-by-concern` off `develop`. Commit format `type(#1314): summary`. First commit copies this plan to `docs/superpowers/plans/`.
- After each task, run in the container: `npm run format`, `npm run build`, then the gate `npm run check`. Never run two Jest processes at once.
- Move lists are generated, never typed. If a line has no match, the helpers print `MISSING …` to stderr; stop and report it.
- One file moves once. A task never moves a file that an earlier task placed.

## Tools (set up once, not committed)

`/tmp/moves-lib.sh`:

```bash
# mods <from-dir> <to-dir> <module>...   one module = its .ts/.spec.ts/.html/.scss siblings
mods() { local from=$1 to=$2; shift 2; for m in "$@"; do local hit=0; for ext in ts spec.ts html scss; do f=$from/$m.$ext; if [ -e "$f" ]; then echo "$f $to/$m.$ext"; hit=1; fi; done; [ $hit = 1 ] || echo "MISSING $from/$m" >&2; done; }
# dirs <to-parent> <dir>...   moves each whole directory under <to-parent>, keeping its name
dirs() { local to=$1; shift; for d in "$@"; do [ -d "$d" ] || echo "MISSING $d" >&2; find "$d" -type f | sort | while read -r f; do echo "$f $to/$(basename "$d")/${f#"$d"/}"; done; done; }
```

`/tmp/move-modules.mjs`: the script from #1304, unchanged. Run it from `frontend/` as `node /tmp/move-modules.mjs <moves-file>`.

```js
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const moves = new Map(
  readFileSync(process.argv[2], 'utf8')
    .split('\n')
    .filter((line) => line.trim())
    .map((line) => line.trim().split(/\s+/).map((part) => path.resolve(root, part))),
);
const walk = (directory) =>
  readdirSync(directory).flatMap((name) => {
    const full = path.join(directory, name);
    return statSync(full).isDirectory() ? walk(full) : [full];
  });
const existing = new Set([...walk(path.join(root, 'src')), ...walk(path.join(root, 'e2e'))]);
for (const from of moves.keys()) if (!existing.has(from)) throw new Error(`missing ${from}`);
const newPath = (file) => moves.get(file) ?? file;

function resolveMoved(fromDirectory, specifier) {
  const base = path.resolve(fromDirectory, specifier);
  const candidates = [base, `${base}.ts`, `${base}/index.ts`, `${base}.scss`,
    path.join(path.dirname(base), `_${path.basename(base)}.scss`)];
  const file = candidates.find((candidate) => existing.has(candidate));
  if (!file) return null;
  const moved = newPath(file);
  return moved === file ? base : moved.slice(0, moved.length - (file.length - base.length));
}

const unresolved = [];
const rewritten = new Map();
for (const file of existing) {
  if (!/\.(ts|scss)$/.test(file)) continue;
  const source = readFileSync(file, 'utf8');
  const oldDirectory = path.dirname(file);
  const newDirectory = path.dirname(newPath(file));
  const result = source.replace(/(['"])(\.\.?\/[^'"\n]*)\1/g, (match, quote, specifier) => {
    const target = resolveMoved(oldDirectory, specifier);
    if (target === null) {
      if (oldDirectory !== newDirectory) unresolved.push(`${path.relative(root, file)}: ${specifier}`);
      return match;
    }
    let relative = path.relative(newDirectory, target);
    if (!relative.startsWith('.')) relative = `./${relative}`;
    return relative === specifier ? match : `${quote}${relative}${quote}`;
  });
  if (result !== source) rewritten.set(newPath(file), result);
}
for (const [from, to] of moves) {
  mkdirSync(path.dirname(to), { recursive: true });
  execFileSync('git', ['mv', from, to]);
}
for (const [file, content] of rewritten) writeFileSync(file, content);
console.log(`${moves.size} moved, ${rewritten.size} rewritten`);
console.log(unresolved.length ? `CHECK by hand:\n${unresolved.join('\n')}` : 'no unresolved literals');
```

---

### Task 1: core/

Root keeps `api`, `problem`, `ai-availability.service` and `version.service`. Three single-consumer modules move to their consumer:
- `reader-location.service` → `settings/`
- `gravatar` → `shared/user-avatar/`
- `save-as` → `settings/backup/`

- [ ] **Step 1: Generate** from `frontend/`:

```bash
bash -c '. /tmp/moves-lib.sh; C=src/app/core
mods $C $C/auth auth.guard admin.guard auth.interceptor auth.service token.store session-identity account-identity passkey.service passkey-device-name passkey-enrol-failure webauthn
mods $C $C/errors boot-error-surface client-error-beacon client-error-http-method client-error-reporter global-error-handler navigation-failure navigation-watchdog
mods $C $C/i18n boot-language language language.service i18n-dictionaries plural-key transloco-loader translated-title.strategy page-title.service locale-writer http-locale-writer
mods $C $C/preferences preferences.service preferences-writer http-preferences-writer magazine-style magazine-style.service magazine-style-writer http-magazine-style-writer digest.service digest-writer http-digest-writer reading-focus.service user-device-storage
mods $C $C/setup setup-api setup.service
mods $C src/app/settings reader-location.service
mods $C src/app/shared/user-avatar gravatar
mods $C src/app/settings/backup save-as
' > /tmp/moves-core.txt; wc -l < /tmp/moves-core.txt
```

  Expect 79 lines; `core/` keeps 7 files. Then run the script.

- [ ] **Step 2: Verify.** `ls src/app/core` shows `api.ts`, `problem.ts`, `ai-availability.service.ts`, `version.service.ts`, their specs and the five folders. Every "CHECK by hand" line is test data, not a path. Run format, build and the gate. Commit `refactor(#1314): core grouped by concern`.

### Task 2: settings/ and admin/

- `settings/` root keeps the shell, hub, nav, sections list, routes, api and models, plus `reader-location.service` from Task 1. Each settings section gets a folder.
- `organise/` keeps its page, store and rows; the health views move to `organise/health/`.
- `admin/` root keeps its api and models.

- [ ] **Step 1: Generate** from `frontend/`:

```bash
bash -c '. /tmp/moves-lib.sh; S=src/app/settings; D=src/app/admin
mods $S $S/account account-section.component email-section.component passkeys-group.component passkey-name-dialog.component
mods $S $S/preferences preferences-section.component
mods $S $S/ai ai-section.component ai-settings.service ai-failure
mods $S $S/recommendations recommendation-settings-card.component recommendation-settings.service recommendation-debug-log.component recommendation-run-history.component recommendation-run-history-month.component run-history-status-icon
mods $S $S/backup backup-section.component backup-archive backup-archive-error backup-part-header backup-problem backup-restore-run
mods $S $S/import import-section.component opml-section.component opml-export
mods $S $S/about about-section.component reading-chart
mods $S/organise $S/organise/health unhealthy-feed-row.component feed-health-facts.component feed-refresh-times.component health-error-dialog.component
mods $D $D/users admin-users.component admin-user-detail.component
mods $D $D/catalog admin-catalog.component category-form-dialog.component feed-form-dialog.component
' > /tmp/moves-settings.txt; wc -l < /tmp/moves-settings.txt
```

  Expect 109. Then run the script.

- [ ] **Step 2: Verify.**
  - `ls src/app/settings` shows only `settings-*`, `settings.*` and `reader-location.service*` files, plus the folders `about account admin ai backup import organise preferences recommendations`.
  - `ls src/app/admin` shows `admin-api.ts`, `admin.models.ts`, `catalog/` and `users/`.
  - The lazy `loadComponent` imports in `settings.routes.ts` point into the new folders.
  - Run format, build and the gate. Commit `refactor(#1314): settings sections and admin pages get folders`.

### Task 3: reader/ and magazine/

`reader/` root keeps `models`, `reader-api`, `format`, `layout.service`, `reading-layout.service`, `reader-gestures`, `audio-player.service` and `media-embeds` plus its JSON: the modules shared across its areas. The areas are:
- `shell/`: the shell component, its services, header, sidebar, search field, view controls, feed intro, audio bar, pane and drawer.
- `list/`: entry list, rows, magazine, list meta and list preferences.
- `entry/`: the actions and pills that list and article share.
- `article/`: reader view, comments, paywall, `content/` loading, `decorators/` that enhance the body, and `reading/` focus.
- `feeds/`: add, manage, tag picker, catalog and feed health.
- `state/`: stores and the sync services.
- `query/`: the URL and query model.
- `scroll/`
- `testing/` (unchanged)

Magazine blocks get one folder each under `list/magazine/blocks/`.

- [ ] **Step 1: Generate** from `frontend/`:

```bash
bash -c '. /tmp/moves-lib.sh; R=src/app/reader; M=$R/magazine
for b in compact hero kicker quote split thumb wide; do mods $M $R/list/magazine/blocks/entry-$b entry-$b.component; done
find $M -type f | sort | grep -vE "/entry-(compact|hero|kicker|quote|split|thumb|wide)\.component\." | while read -r f; do echo "$f $R/list/magazine/${f#$M/}"; done
mods $R $R/shell reader-shell.component drawer-swipe.directive pane-resize.directive pane-split pane-split.service refresh-message sidebar-counts-poll.service sidebar-visibility.service passkey-offer-dialog.component
dirs $R/shell $R/header $R/sidebar $R/search-field $R/view-controls $R/feed-intro $R/audio-player-bar
dirs $R/list $R/entry-list $R/entry-row $R/entry-meta $R/caught-up-illustration $R/recommendation-strip $R/run-header $R/for-you-progress
mods $R $R/list for-you-runs list-order.service list-preferences.service unread-filter.service paging preview-image
mods $R $R/list/for-you-progress eta-format
dirs $R/entry $R/entry-actions $R/entry-pills
dirs $R/article $R/reader-view $R/entry-comments $R/paywall-notice
mods $R $R/article/entry-comments comments.service
mods $R $R/article/content reader-content.service reader-cache.service reader-mode.service entry-body.service reader-load-error
mods $R $R/article/decorators audio-attachment code-highlight hls-streams lead-paragraph reader-cards reader-faq reader-image-fit reader-narration reader-slideshow reading-time
mods $R $R/article/reading reading-focus reading-focus-applier reading-progress reading-sections
dirs $R/feeds $R/add-feed $R/manage $R/tag-picker $R/catalog
mods $R $R/feeds feed-health
mods $R $R/state entries.store subscriptions.store tags.store saved-searches.store sidebar-freshness recommendations.service refresh.service
mods $R $R/query query slug reader-matcher reader-matcher-navigation
mods $R $R/scroll header-scroll list-scroll-memory list-scroll-reset reader-scroller scroll-outside-zone.directive
' > /tmp/moves-reader.txt; wc -l < /tmp/moves-reader.txt; awk '{print $1}' /tmp/moves-reader.txt | sort | uniq -d
```

  Expect 267 lines and no duplicate sources. Then run the script. The script creates `list/magazine/…` before the emptied `magazine/` is removed, and git drops empty directories on its own.

- [ ] **Step 2: Verify.**
  - `ls -p src/app/reader` shows the root modules named above plus `article/ entry/ feeds/ list/ query/ scroll/ shell/ state/ testing/`.
  - `git grep -n "reader/shell/reader-shell.component" src/app/app.routes.ts` finds the lazy route.
  - `shell/reader-shell.component.scss` and every other moved `.scss` resolve their `@use` paths; the build proves it.
  - `e2e/list-header-narrow-pane.spec.ts` now imports `../src/app/reader/shell/pane-split`.
  - Run format, build and the gate.
  - On http://localhost:4200, check that the reader loads, the magazine list renders, an article opens, the split resizes, settings sections open, and admin users open.

- [ ] **Step 3: Path mentions outside the frontend source.** Fix each hit to the new path:

```bash
git grep -nE "src/app/(core|settings|admin|reader)/[A-Za-z./-]+" -- ':!docs/superpowers' ':!frontend/src' ':!frontend/e2e'
```

  On `develop`, the hits needing an update are:
  - `backend/src/Service/ReaderAudit/Model/ReaderLinkModel.php:8`: `reader/slug.ts` becomes `reader/query/slug.ts`.
  - `docs/design-language.md:1483`: `reader/magazine/` becomes `reader/list/magazine/`.

  `reader/models.ts`, `settings/settings-sections.ts` and the embed JSON stay where they are. Check any other hit.

- [ ] **Step 4:** Commit `refactor(#1314): reader grouped by area; magazine blocks get folders`, open the PR (`Closes #1314`) and merge when green.
