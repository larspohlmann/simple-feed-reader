# Names, reader/ folder roles and comment trim — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans (inline; one commit per task). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Close the #1297 lint ratchet for naming by flipping `id-length` and `unicorn/prevent-abbreviations` to `error`. Group the 60 loose modules in `reader/` by role. Delete the path headers and the history narration in comments (#1304).

**Architecture:** Three mechanical passes, each checked by the build, lint and Jest:
1. Comments are deleted with a one-line perl script.
2. Files move with a small throwaway node script that `git mv`s them and rewrites every relative specifier in `src/` and `e2e/`.
3. Names are renamed by ESLint's scope-aware autofix (unicorn) wherever a name has exactly one meaning. The rest is renamed by hand.

No behaviour changes.

**Tech Stack:** Angular 20, ESLint 9 flat config, eslint-plugin-unicorn 65, Jest in the frontend container.

**Out of scope (lean):**
- `media-embeds.ts` and `embed-frame-allowlist.generated.json` stay in `reader/`, because `DumpEmbedFrameAllowlistCommand` writes that path.
- The size and complexity rules stay at `warn`.
- A comment that is merely longer than 3 lines stays; the 3-line limit is only a recommendation now.

## Global Constraints

- Starts after #1303 (merged as `cd8c7fb18`). Branch `refactor/1304-names-folders-comments` off `develop`. Commit format `type(#1304): summary`. First commit copies this plan to `docs/superpowers/plans/`.
- Gate: `docker compose exec -T frontend npm run check`, plus `npm run build` in the container after Task 2.
- Never run two Jest processes at once; the container runs out of memory. Subagents may run `npx eslint` on their own files, never Jest.
- A rename is a rename. Never change a string literal, a URL, an assertion's expected value or a property name (`properties: "never"` / `checkProperties: false` exempt them).

---

### Task 1: Comment trim + prettierignore

**Files:** the 65 files whose first line is a `// src/app/…` path header, the narration candidates listed below, and `frontend/.prettierignore`

- [ ] **Step 1: Path headers.** Every header is on line 1. From `frontend/`:

```bash
grep -rlE "^// (frontend/)?src/app/" src e2e | xargs perl -i -ne 'print unless $. == 1 && m{^// (?:frontend/)?src/app/}; $. = 0 if eof'
```

  Afterwards `grep -rlE "^// (frontend/)?src/app/" src e2e` prints nothing.

- [ ] **Step 2: Narration.** List the candidates (53 lines on `develop`):

```bash
grep -rniE "^\s*(//|\*|/\*\*).*\b(previously|used to|no longer|was (moved|renamed|removed|changed)|reviewer|review (found|asked|flagged)|we now|now (lives|uses|comes))\b" src --include='*.ts' --include='*.scss' --include='*.html'
```

  Judge each hit against the CLAUDE.md comment bar:
  - A comment that tells the story of a change or a review ("used to…", "review flagged…", "now lives in…") is deleted.
  - When the comment guards a regression test, rewrite it as one present-tense sentence stating the invariant, and keep the issue number, e.g. `// #87: the header stays in flow; a negative margin-top hid it.`
  - A hit that is not narration stays, for example "used to resume a poll loop", which describes present behaviour.
  - Do not hunt beyond this list.

- [ ] **Step 3: Carry-forward from #1301.** Append `src/app/reader/embed-frame-allowlist.generated.json` to `frontend/.prettierignore`.

- [ ] **Step 4:** Run `docker compose exec -T frontend npm run format`, then the gate. Commit `refactor(#1304): drop path headers and change narration from comments`.

### Task 2: reader/ folders by role

**Files:** 88 moves (listed in Step 1) plus every importer. The script rewrites the importers.

The `reader/` root keeps the modules that several subfolders or other features share:
- `models`, `reader-api`, `format`, `paging`, `preview-image`, `feed-health`, `reader-gestures`
- `layout.service`, `reading-layout.service`, `list-order.service`, `list-preferences.service`, `unread-filter.service`
- `entry-body.service`, `recommendations.service`, `refresh.service`, `audio-player.service`
- `media-embeds` plus its JSON

- [ ] **Step 1: The move list.** From `frontend/`:

```bash
bash -c '
group() { local to=$1; shift; for m in "$@"; do for ext in ts spec.ts html scss; do f=src/app/reader/$m.$ext; [ -e "$f" ] && echo "$f $to/$m.$ext"; done; done; }
group src/app/reader/shell reader-shell.component drawer-swipe.directive pane-resize.directive pane-split pane-split.service refresh-message sidebar-counts-poll.service sidebar-visibility.service passkey-offer-dialog.component
group src/app/reader/reader-view/article audio-attachment code-highlight hls-streams lead-paragraph reader-cards reader-faq reader-image-fit reader-load-error reader-narration reader-slideshow reading-time reader-content.service reader-cache.service reader-mode.service
group src/app/reader/reading reading-focus reading-focus-applier reading-progress reading-sections
group src/app/reader/state entries.store subscriptions.store tags.store saved-searches.store sidebar-freshness
group src/app/reader/query query slug reader-matcher reader-matcher-navigation
group src/app/reader/scroll header-scroll list-scroll-memory list-scroll-reset reader-scroller scroll-outside-zone.directive
group src/app/reader/entry-comments comments.service
group src/app/reader/for-you-progress eta-format
group src/app/reader/entry-list for-you-runs
' > /tmp/moves-1304.txt
wc -l < /tmp/moves-1304.txt
```

  Expect `88`.

- [ ] **Step 2: The move script.** Save it as `/tmp/move-modules.mjs`; it is not committed. It resolves every `'./…'` or `'../…'` string literal in `.ts` and `.scss` files against the file's old location. It then rewrites the literal when the file moved or the target moved, and prints any string it could not resolve in a moved file, for you to check by hand.

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

- [ ] **Step 3: Run it** from `frontend/`: `node /tmp/move-modules.mjs /tmp/moves-1304.txt`.
  - Expect `88 moved`.
  - Every "CHECK by hand" line must be a string that isn't a path, such as test data. Fix a real path by hand.
  - `reader/shell/reader-shell.component.scss`'s `@use '../theme/breakpoints'` must now read `'../../theme/breakpoints'`.

- [ ] **Step 4: Verify.**
  - `ls src/app/reader/*.ts | grep -v spec` lists the 17 root modules named above.
  - The lazy route in `app.routes.ts` now imports `./reader/shell/reader-shell.component`.
  - `e2e/list-header-narrow-pane.spec.ts` imports `../src/app/reader/shell/pane-split`.
  - Run `docker compose exec -T frontend npm run format`, then `npm run build` and the gate in the container.
  - Open http://localhost:4200 once: the reader loads, an article opens, and the list/reader split can be resized.
  - Commit `refactor(#1304): reader modules grouped by role`.

### Task 3: Names; both naming rules become errors

**Files:** `frontend/eslint.config.js` and about 248 files that currently carry naming warnings. On `develop` there are 1870 `id-length` and 1179 `prevent-abbreviations` findings; 2543 of them are in specs.

- [ ] **Step 1: Permanent config.** In the main rules block of `frontend/eslint.config.js`, replace the `unicorn/prevent-abbreviations` entry with the version below. The rule stays at `warn` until Step 5.
  - `ref`/`params`/`param` are Angular's own vocabulary (`DialogRef`, `ElementRef`, `queryParams`, `ParamMap`), so they are allowed.
  - `req` in a spec is an Angular `TestRequest`, so it is renamed to `testRequest`. The default replacement would produce `request.request.method`.

```js
      "unicorn/prevent-abbreviations": [
        "warn",
        {
          checkFilenames: false,
          checkProperties: false,
          replacements: {
            svc: { service: true },
            recs: { recommendations: true },
            subs: { subscriptions: true },
            hdr: { header: true },
            grp: { group: true },
            ref: false,
            params: false,
            param: false,
          },
        },
      ],
```

  In the existing `**/*.spec.ts` override block, add:

```js
      "unicorn/prevent-abbreviations": [
        "warn",
        {
          checkFilenames: false,
          checkProperties: false,
          replacements: {
            svc: { service: true },
            recs: { recommendations: true },
            subs: { subscriptions: true },
            hdr: { header: true },
            grp: { group: true },
            ref: false,
            params: false,
            param: false,
            req: { testRequest: true },
            f: { fixture: true },
          },
        },
      ],
```

  `f` is always a fixture in the specs (`const f = mount(…)` / `createComponent` / `boot` / `create`, 865 hits). A helper that returns something other than a fixture gets its own name in Step 3.

- [ ] **Step 2: Autofix.** From the repo root:

```bash
docker compose exec -T frontend npx eslint --fix "src/**/*.ts"
docker compose exec -T frontend npm run format
```

  unicorn renames through scope analysis, so every reference follows. Currently no other fixable rule reports, so `--fix` changes only names. Then check what the autofix could not name cleanly:
  - `git diff -U0 | grep -nE "^\+.*\b[a-z][A-Za-z0-9]*_\b"`: unicorn appends `_` when the new name would clash. Rename each hit by hand.
  - `git grep -nE "\btestRequest\b" src | grep -v "\.spec\.ts"` must print nothing.
  - Interceptor specs where `req` held an `HttpRequest` rather than a `TestRequest`: rename them to `request`.

- [ ] **Step 3: The remainder by hand.** List it with `docker compose exec -T frontend npx eslint "src/**/*.ts" | grep -E "id-length|prevent-abbreviations|^/"`. ESLint 9 has no `unix` formatter; the default output prints the file path above its findings. Expect roughly 900: `e`, `r`, `s`, `b`, `c`, `a`, `n`, `m`, `t`, `p`, plus `res`, `dir`, `fn`. Name each by what it holds:

| Short | Typical full name |
|---|---|
| `e` | `event` or `error`, by type |
| `(a, b)` in a comparator | `(left, right)` |
| `r` | `request` (HTTP predicate), `route`, `resolve` (a Promise executor), `response`, `result` |
| `s` | `subscription`, `service`, `state`, `search`, per the value |
| `c` | `component` (`f.componentInstance`), `challenge`, `count` |
| `b` / `t` / `n` / `p` / `m` | `block`/`button`, `tag`/`time`, `count`/`node`, `part`/`promise`, `match` |
| `[k, v]` from `Object.entries` | `[key, value]` |
| `res` | `response` or `result` |
| `fn` / `dir` | `callback` / `direction` or `directory` |

  The files split cleanly by top folder: `reader/`, `settings/`, and the rest. You may give each folder to a parallel subagent. Each subagent edits only its own folder and checks it with `npx eslint <folder>`, never Jest.

- [ ] **Step 4: Verify the remainder is zero.** `docker compose exec -T frontend npx eslint "src/**/*.ts" | grep -cE "id-length|prevent-abbreviations"` prints `0`.

- [ ] **Step 5: Flip.** In `eslint.config.js`, set `id-length` and both `unicorn/prevent-abbreviations` entries (the main block and the spec override) from `"warn"` to `"error"`.
  - Break-test: add `const q = 1; void q;` to `src/app/core/api.ts`. Expect an `id-length` **error**, then remove the line by editing.
  - Run the gate.
  - Commit `refactor(#1304): full names; naming rules are errors`, open the PR (`Closes #1304`) and merge when green.
