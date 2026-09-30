# e2e Runs Send Their Mail to Mailpit (#1287) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An e2e run never mails through the admin's saved mail server. A new env switch, `MAILER_FORCE_FALLBACK=1`, makes the app send through `MAILER_FALLBACK_DSN` (Mailpit in the dev stack) even when `mail_server_settings` holds a server. Both e2e entry points turn it on for their run and put it back afterwards. One PR, `Closes #1287`.

**Architecture:**
- `EffectiveMailSettings::configuredTransport()` returns `null` while the switch is on. `DynamicMailTransport` already reads `null` as "send through the env fallback", so nothing else in the send path changes.
- The switch is an env var read once through `%env(bool:MAILER_FORCE_FALLBACK)%`. It defaults to `0` in `backend/.env`, is passed through to `php` and `worker` by `docker-compose.yml`, and is forced to `0` for PHPUnit by `phpunit.dist.xml`.
- `backend/bin/e2e-mail-fallback.sh` (new, sourced or run directly, like `e2e-preflight.sh`):
  - `force` recreates `php` with the switch on, restarts `nginx`, waits for `/api/health`, and checks that the running container reads `1`.
  - `restore` recreates `php` with it off.
  - `backend/bin/e2e.sh` calls both through its EXIT trap.
  - `frontend/e2e/global-setup.ts` calls `force` and returns `restore` as Playwright's global teardown.
- It fails closed: when the stack cannot be switched, the run stops before any mail can leave.
- `.github/workflows/e2e-rot-check.yml` sets the switch at job level, so CI's stack boots with it on and both scripts find it on already.

**Tech Stack:** PHP 8.4, Symfony 7.4 (env processors, `#[Autowire]`), PHPUnit 12 (`KernelTestCase`), bash 3.2-safe shell, Docker Compose v2 interpolation, Playwright 1.61 (`globalSetup` returning a teardown), GitHub Actions job `env:`.

**Spec:** GitHub issue #1287; the design choice was Lars's answer on 2026-09-30, "Env switch for e2e runs".

## Global Constraints

- CLAUDE.md "PHP code style": Clean Code, `final readonly`, no `@phpstan-ignore`, comments default none and at most three lines. This holds for shell and TypeScript too.
- Never run an e2e suite that sends mail against the dev stack before Task 5 proves the switch works. The dev DB's `mail_server_settings` routes through a real Gmail account, and it is not ours to change.
- Every e2e command runs from the checkout that owns the Docker stack (CLAUDE.md, `e2e-preflight.sh`).
- A php recreate needs `docker compose restart nginx`: nginx otherwise keeps the old php IP.
- Commits are `type(#1287): …`, one per task, with no attribution lines.
- `$SCRATCH` is the session scratchpad directory. `grep -q` never follows a producer under `pipefail`: its early exit SIGPIPEs the producer, and the pipe reads as failed.
- Work in place, no worktrees, no stash. Run `git status --short` before any `switch`.

---

### Task 0: Branch and plan

- [ ] **Step 1:** Run `git status --short && git branch --show-current`, then `git fetch -q origin && git switch -c fix/1287-e2e-mail-fallback origin/develop`.
- [ ] **Step 2:** Commit this plan with `git add docs/superpowers/plans/2026-09-30-1287-e2e-mail-fallback.md && git commit -m "docs(#1287): add plan"`.

---

### Task 1: The app sends through the env fallback while `MAILER_FORCE_FALLBACK` is on

**Files:**
- Modify: `backend/src/Service/Mail/MailSendingSettings/EffectiveMailSettings.php`
- Modify: `backend/tests/Service/Mail/MailSendingSettings/EffectiveMailSettingsTest.php`
- Modify: `backend/.env`, `backend/phpunit.dist.xml`, `docker-compose.yml`

**Interfaces:**
- Produces: the env var `MAILER_FORCE_FALLBACK` (`0`/`1`), read by the `php` and `worker` containers. Tasks 2–4 set it.

- [ ] **Step 1: The failing test.** In `EffectiveMailSettingsTest.php`, add these imports.

Before:
```php
use App\Enum\MailEncryption;
use App\Service\Mail\MailSendingSettings\EffectiveMailSettings;
use App\Service\Mail\Settings\MailFallback;
```
After:
```php
use App\Enum\MailEncryption;
use App\Repository\MailServerSettingsRepository;
use App\Service\Mail\MailSendingSettings\EffectiveMailSettings;
use App\Service\Mail\Settings\Crypto\MailPasswordCipher;
use App\Service\Mail\Settings\MailFallback;
```
Add the test directly after `testAnEnabledRowTurnsSendingOn()`:
```php
    public function testTheFallbackSwitchIgnoresASavedMailServer(): void
    {
        $this->settings()->update(
            SettingsRequests::mail(enabled: true, host: 'smtp.relay.test', password: 'p')->toUpdate(),
        );
        $fallbackOnly = new EffectiveMailSettings(
            self::getContainer()->get(MailServerSettingsRepository::class),
            self::getContainer()->get(MailPasswordCipher::class),
            self::getContainer()->get(MailFallback::class),
            fallbackOverridesStoredTransport: true,
        );

        self::assertNull($fallbackOnly->configuredTransport());
        self::assertTrue($fallbackOnly->isSendingEnabled());
        self::assertNotNull($this->effective()->configuredTransport());
    }
```
The last assertion is the positive control: the container's instance, with the switch off, still reads the saved server.

- [ ] **Step 2: It fails.** Run `php bin/phpunit --filter testTheFallbackSwitchIgnoresASavedMailServer` from `backend/`. Expected: `Error: Unknown named parameter $fallbackOverridesStoredTransport`.

- [ ] **Step 3: The switch.** In `EffectiveMailSettings.php`, first the imports.

Before:
```php
use App\Service\Mail\Settings\Model\ResolvedMailTransportModel;

final readonly class EffectiveMailSettings implements MailSendingSettingsInterface
{
    public function __construct(
        private MailServerSettingsRepository $mailServerSettings,
        private MailPasswordCipher $cipher,
        private MailFallback $fallback,
    ) {
    }
```
After:
```php
use App\Service\Mail\Settings\Model\ResolvedMailTransportModel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class EffectiveMailSettings implements MailSendingSettingsInterface
{
    public function __construct(
        private MailServerSettingsRepository $mailServerSettings,
        private MailPasswordCipher $cipher,
        private MailFallback $fallback,
        #[Autowire('%env(bool:MAILER_FORCE_FALLBACK)%')]
        private bool $fallbackOverridesStoredTransport,
    ) {
    }
```
Then the check.

Before:
```php
        $settings = $this->mailServerSettings->findSingleton();

        if (null === $settings || '' === $settings->getHost()) {
            return null;
        }
```
After:
```php
        $settings = $this->mailServerSettings->findSingleton();

        if ($this->fallbackOverridesStoredTransport || null === $settings || '' === $settings->getHost()) {
            return null;
        }
```

- [ ] **Step 4: The env default, the test pin and the compose pass-through.**

`backend/.env`, before:
```
MAIL_FROM_NAME="Simple Feed Reader"
###< symfony/mailer ###
```
After:
```
MAIL_FROM_NAME="Simple Feed Reader"
# 1 sends through MAILER_FALLBACK_DSN even when an admin saved a mail server; the e2e runs set it (#1287).
MAILER_FORCE_FALLBACK=0
###< symfony/mailer ###
```
`backend/phpunit.dist.xml`, before:
```xml
        <server name="MAILER_FALLBACK_DSN" value="null://null" />
```
After:
```xml
        <server name="MAILER_FALLBACK_DSN" value="null://null" />
        <env name="MAILER_FORCE_FALLBACK" value="0" force="true" />
        <server name="MAILER_FORCE_FALLBACK" value="0" />
```
`docker-compose.yml` has the line `MAILER_FALLBACK_DSN: "smtp://mailpit:1025"` twice: once in `php` (`:54`) and once in `worker` (`:142`). Replace both with the Edit tool (`replace_all: true`):
```yaml
      MAILER_FALLBACK_DSN: "smtp://mailpit:1025"
      MAILER_FORCE_FALLBACK: "${MAILER_FORCE_FALLBACK:-0}"
```

- [ ] **Step 5: It passes.** Run `php bin/phpunit tests/Service/Mail` and `bin/console lint:container`. Expected: `OK`, then `[OK] The container was linted successfully`. Also check the compose resolution from the repository root:
```bash
docker compose config --format json php worker | jq -r '.services | to_entries[] | select(.key=="php" or .key=="worker") | "\(.key) \(.value.environment.MAILER_FORCE_FALLBACK)"'
MAILER_FORCE_FALLBACK=1 docker compose config --format json php worker | jq -r '.services | to_entries[] | select(.key=="php" or .key=="worker") | "\(.key) \(.value.environment.MAILER_FORCE_FALLBACK)"'
```
Expected: `php 0`, `worker 0`, then `php 1`, `worker 1`.

- [ ] **Step 6: Deletion checks, each restored with the Edit tool.**
  - (a) Delete `$this->fallbackOverridesStoredTransport || ` from the check. Run the Step 2 filter. Expected FAIL: `Failed asserting that App\Service\Mail\Settings\Model\ResolvedMailTransportModel Object (…) is null.`
  - (b) In `phpunit.dist.xml`, set both new `MAILER_FORCE_FALLBACK` values to `1`. Run `php bin/phpunit tests/Service/Mail/MailSendingSettings/EffectiveMailSettingsTest.php`. Expected: at least `testTheConfiguredTransportCarriesEveryFieldAndTheOpenedPassword` FAILs (`Failed asserting that null is not null.`). This proves the env reaches the service through the container.
  - Rerun Step 5.

- [ ] **Step 7: Commit.**
```bash
git add backend/src/Service/Mail/MailSendingSettings/EffectiveMailSettings.php backend/tests/Service/Mail/MailSendingSettings/EffectiveMailSettingsTest.php backend/.env backend/phpunit.dist.xml docker-compose.yml
git commit -m "fix(#1287): MAILER_FORCE_FALLBACK sends through the env fallback over a saved mail server"
```

---

### Task 2: `composer e2e` switches the stack's mail to Mailpit for its run

**Files:**
- Create: `backend/bin/e2e-mail-fallback.sh`
- Modify: `backend/bin/e2e.sh`

**Interfaces:**
- Produces: `force_mail_fallback <repo-root> <base-url>`, which prints `forced` or `already` on stdout. It returns 1 when the switch did not take, and 2 when there is no Docker or no running `php`.
- Produces: `restore_mail_fallback <repo-root> <base-url>`.
- Produces a direct invocation: `bash backend/bin/e2e-mail-fallback.sh force|restore <repo-root> <base-url>`. Task 3 uses it.

- [ ] **Step 1: The helper.** Create `backend/bin/e2e-mail-fallback.sh`:
```bash
#!/usr/bin/env bash
# Routes the running stack's mail to Mailpit for an e2e run (#1287): the app otherwise sends through the admin's
# saved mail server. Sourced by bin/e2e.sh, run directly by frontend/e2e/global-setup.ts.

mail_fallback_compose() {
  local repo_root="$1"
  shift
  docker compose -f "$repo_root/docker-compose.yml" "$@"
}

mail_fallback_value() {
  mail_fallback_compose "$1" exec -T php printenv MAILER_FORCE_FALLBACK 2>/dev/null || true
}

mail_fallback_wait_for_api() {
  local attempt
  for attempt in $(seq 1 60); do
    if curl -fsS -o /dev/null "$1/api/health"; then
      return 0
    fi
    sleep 1
  done
  echo "ERROR: $1/api/health did not come back after recreating php (${attempt} s)." >&2
  return 1
}

# A php recreate alone strands nginx on the old container IP.
mail_fallback_recreate_php() {
  local repo_root="$1" base_url="$2" value="$3"
  MAILER_FORCE_FALLBACK="$value" mail_fallback_compose "$repo_root" up -d php >&2
  mail_fallback_compose "$repo_root" restart nginx >&2
  mail_fallback_wait_for_api "$base_url"
}

force_mail_fallback() {
  local repo_root="$1" base_url="$2"
  if ! command -v docker >/dev/null 2>&1 \
    || ! mail_fallback_compose "$repo_root" ps --status running --services 2>/dev/null | grep -x php >/dev/null; then
    return 2
  fi
  if [ "$(mail_fallback_value "$repo_root")" = "1" ]; then
    echo already
    return 0
  fi
  mail_fallback_recreate_php "$repo_root" "$base_url" 1 || return 1
  if [ "$(mail_fallback_value "$repo_root")" != "1" ]; then
    echo "ERROR: php still does not read MAILER_FORCE_FALLBACK=1; refusing to run e2e against a real mail server." >&2
    return 1
  fi
  echo forced
}

restore_mail_fallback() {
  mail_fallback_recreate_php "$1" "$2" 0
}

# Run only when executed directly, not when sourced (bash 3.2 safe).
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  set -euo pipefail
  case "${1:-}" in
    force) force_mail_fallback "${2:?repo root}" "${3:?base url}" ;;
    restore) restore_mail_fallback "${2:?repo root}" "${3:?base url}" ;;
    *) echo "usage: e2e-mail-fallback.sh force|restore <repo-root> <base-url>" >&2; exit 64 ;;
  esac
fi
```

- [ ] **Step 2: `e2e.sh` sources it.**

Before:
```bash
source "$BACKEND_DIR/bin/e2e-preflight.sh"
```
After:
```bash
source "$BACKEND_DIR/bin/e2e-preflight.sh"
# shellcheck source=e2e-mail-fallback.sh
source "$BACKEND_DIR/bin/e2e-mail-fallback.sh"
```

- [ ] **Step 3: `e2e.sh` restores the mail transport last in its EXIT trap.**

Before:
```bash
CA_BUNDLE=""
SETTINGS_TO_RESTORE=""
```
After:
```bash
CA_BUNDLE=""
SETTINGS_TO_RESTORE=""
MAIL_FALLBACK_FORCED=""
```
Before:
```bash
      echo "WARNING: set the registration gates by hand under Settings → Admin → Registration." >&2
    fi
  fi
}
trap cleanup EXIT
```
After:
```bash
      echo "WARNING: set the registration gates by hand under Settings → Admin → Registration." >&2
    fi
  fi
  if [ -n "$MAIL_FALLBACK_FORCED" ]; then
    echo "==> Sending the stack's mail through its saved mail server again ..."
    if ! restore_mail_fallback "$REPO_ROOT" "$BASE_URL"; then
      echo "WARNING: php still sends through Mailpit; run: (cd '$REPO_ROOT' && docker compose up -d php && docker compose restart nginx)" >&2
    fi
  fi
}
trap cleanup EXIT

echo "==> Sending the stack's mail to Mailpit for this run ..."
MAIL_FALLBACK_OUTCOME="$(force_mail_fallback "$REPO_ROOT" "$BASE_URL")"
if [ "$MAIL_FALLBACK_OUTCOME" = "forced" ]; then
  MAIL_FALLBACK_FORCED=1
fi
```
Under `set -e`, a failing `force_mail_fallback` (1 or 2) stops the script before the suite: fail closed. Exit 2 cannot occur here, because the script checked `/api/health` at its top. If the force fails after the recreate, the stack stays on Mailpit, which is the safe direction. The warning names the manual restore.

- [ ] **Step 4: Lint.** From `backend/`, run `shellcheck bin/e2e-mail-fallback.sh bin/e2e.sh` and `bash -n bin/e2e-mail-fallback.sh`. Expected: no output. CI's ShellCheck step does not cover `backend/bin`, so this local run is the gate.

- [ ] **Step 5: The helper, standalone, without running any suite (from the repository root).** First run `bash backend/bin/e2e-preflight.sh "$(pwd)"; echo "preflight exit $?"`. Expected: `preflight exit 0`.
```bash
docker compose ps --status running --services | tee "$SCRATCH/1287-running-before.txt"
bash backend/bin/e2e-mail-fallback.sh force "$(pwd)" https://localhost:8443
docker compose exec -T php printenv MAILER_FORCE_FALLBACK
bash backend/bin/e2e-mail-fallback.sh force "$(pwd)" https://localhost:8443
bash backend/bin/e2e-mail-fallback.sh restore "$(pwd)" https://localhost:8443
docker compose exec -T php printenv MAILER_FORCE_FALLBACK
curl -fsS -o /dev/null https://localhost:8443/api/health && echo "api up"
```
Expected, in order: `forced`, `1`, `already`, the recreate's compose lines on stderr, `0`, `api up`.

- [ ] **Step 6: Deletion check: the helper fails closed.** In `mail_fallback_recreate_php`, replace `MAILER_FORCE_FALLBACK="$value" mail_fallback_compose` with `MAILER_FORCE_FALLBACK=0 mail_fallback_compose`. Then run `bash backend/bin/e2e-mail-fallback.sh force "$(pwd)" https://localhost:8443; echo "exit $?"`. Expected: `ERROR: php still does not read MAILER_FORCE_FALLBACK=1; refusing to run e2e against a real mail server.` and `exit 1`. Restore the line with the Edit tool and rerun Step 5.

- [ ] **Step 7: Commit.**
```bash
git add backend/bin/e2e-mail-fallback.sh backend/bin/e2e.sh
git commit -m "fix(#1287): composer e2e sends the stack's mail to Mailpit for its run"
```

---

### Task 3: The Playwright run does the same

**Files:**
- Modify: `frontend/e2e/global-setup.ts`

**Interfaces:**
- Consumes: `bash backend/bin/e2e-mail-fallback.sh force|restore <repo-root> <base-url>` (Task 2). `force` prints `forced` or `already`, exits 1 when the switch did not take, and exits 2 when there is no stack.

- [ ] **Step 1: Force before the fixtures; return the restore as the global teardown.**

Before:
```ts
export default function globalSetup(): void {
  const repoRoot = resolve(__dirname, '..', '..');
  const composeFile = resolve(repoRoot, 'docker-compose.yml');
  const preflightScript = resolve(repoRoot, 'backend', 'bin', 'e2e-preflight.sh');

  assertStackOwnsCheckout(preflightScript, repoRoot);
```
After:
```ts
export default function globalSetup(): (() => void) | undefined {
  const repoRoot = resolve(__dirname, '..', '..');
  const composeFile = resolve(repoRoot, 'docker-compose.yml');
  const preflightScript = resolve(repoRoot, 'backend', 'bin', 'e2e-preflight.sh');

  assertStackOwnsCheckout(preflightScript, repoRoot);
  const restoreMailTransport = sendMailToMailpit(repoRoot);
```
Before (the end of `globalSetup`):
```ts
      console.warn(
        `[global-setup] Skipping e2e fixture step "${consoleArgs.join(' ')}": ${reason}`,
      );
    }
  }
}
```
After:
```ts
      console.warn(
        `[global-setup] Skipping e2e fixture step "${consoleArgs.join(' ')}": ${reason}`,
      );
    }
  }

  return restoreMailTransport;
}

// #1287: the app mails through the admin's saved mail server; the run switches it to Mailpit and back.
// Exit 2 (no Docker, no stack) stays best-effort like the rest; exit 1 means the switch failed, so no spec runs.
function sendMailToMailpit(repoRoot: string): (() => void) | undefined {
  const script = resolve(repoRoot, 'backend', 'bin', 'e2e-mail-fallback.sh');
  let outcome: string;
  try {
    outcome = execFileSync('bash', [script, 'force', repoRoot, API_BASE_URL], {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'inherit'],
    }).trim();
  } catch (error) {
    if ((error as { status?: number }).status !== 2) {
      throw error;
    }
    console.warn('[global-setup] No running stack; mail transport left as it is.');
    return undefined;
  }
  if (outcome !== 'forced') {
    return undefined;
  }
  return () => {
    execFileSync('bash', [script, 'restore', repoRoot, API_BASE_URL], { stdio: 'inherit' });
  };
}
```
Also add the constant under `FIXTURE_COMMANDS`:
```ts
const API_BASE_URL = process.env['E2E_API_BASE_URL'] ?? 'https://localhost:8443';
```
The `status` read uses the same cast `assertStackOwnsCheckout` already uses.

- [ ] **Step 2: The frontend gate.** From the repository root, run `docker compose exec -T frontend npm run check`. Expected: exit 0 (ESLint, Prettier, Stylelint, Jest).

- [ ] **Step 3: Commit.**
```bash
git add frontend/e2e/global-setup.ts
git commit -m "fix(#1287): the Playwright run sends the stack's mail to Mailpit and back"
```

---

### Task 4: CI boots the rot check's stack with the switch on; the docs say so

**Files:**
- Modify: `.github/workflows/e2e-rot-check.yml`
- Modify: `docs/local-docker.md`

- [ ] **Step 1: Job env.**

Before:
```yaml
    env:
      OTEL_PHP_AUTOLOAD_ENABLED: 'false'
      OTEL_PHP_DISABLED_INSTRUMENTATIONS: all
```
After:
```yaml
    env:
      OTEL_PHP_AUTOLOAD_ENABLED: 'false'
      OTEL_PHP_DISABLED_INSTRUMENTATIONS: all
      # Boots php sending through Mailpit, so both e2e scripts find the switch on and recreate nothing (#1287).
      MAILER_FORCE_FALLBACK: '1'
```
Check that it parses:
```bash
php -r 'require "backend/vendor/autoload.php"; $w = Symfony\Component\Yaml\Yaml::parseFile(".github/workflows/e2e-rot-check.yml"); echo json_encode($w["jobs"]["e2e"]["env"]), "\n";'
```
Expected: `{"OTEL_PHP_AUTOLOAD_ENABLED":"false","OTEL_PHP_DISABLED_INSTRUMENTATIONS":"all","MAILER_FORCE_FALLBACK":"1"}`.

- [ ] **Step 2: Docs.** In `docs/local-docker.md`.

Before:
```markdown
`composer test`: it turns the stack's OpenTelemetry instrumentation off, which
a bare `vendor/bin/phpunit` inherits and pays for on every query.
```
After:
```markdown
`composer test`: it turns the stack's OpenTelemetry instrumentation off, which
a bare `vendor/bin/phpunit` inherits and pays for on every query.

Both e2e entry points (`composer e2e`, `npm run e2e`) send their mail to Mailpit
even when an admin has saved a real mail server: they recreate `php` with
`MAILER_FORCE_FALLBACK=1` for the run and put it back afterwards (#1287). If a
run is killed before its cleanup, `docker compose up -d php` followed by
`docker compose restart nginx` puts it back by hand.
```

- [ ] **Step 3: Commit.**
```bash
git add .github/workflows/e2e-rot-check.yml docs/local-docker.md
git commit -m "fix(#1287): the rot check boots with mail on Mailpit, and the docs say so"
```

---

### Task 5: Prove it on the dev stack, then the gates

Run from the repository root, in the foreground. Stop the worker first, as `docs/local-docker.md` asks before any e2e run, and start it again at the end only if it was running.

- [ ] **Step 1: The wiring reaches the running container.**
```bash
docker compose exec -T php bin/console debug:container 'App\Service\Mail\MailSendingSettings\EffectiveMailSettings' --show-arguments | grep -F 'MAILER_FORCE_FALLBACK'
```
Expected: one line naming `%env(bool:MAILER_FORCE_FALLBACK)%`.

- [ ] **Step 2: The full backend e2e suite, mail tests included.**
```bash
before=$(curl -s 'http://localhost:8025/api/v1/messages?limit=1' | jq .total)
docker compose stop worker
(cd backend && composer e2e -- --exclude-group external-site)
after=$(curl -s 'http://localhost:8025/api/v1/messages?limit=1' | jq .total)
echo "mailpit $before -> $after"
docker compose exec -T php printenv MAILER_FORCE_FALLBACK
```
Expected:
- The script prints `==> Sending the stack's mail to Mailpit for this run ...`.
- `OK (6 tests, …)`: the three tests that used to wait out their Mailpit timeout now pass.
- `mailpit before -> after` with `after > before`.
- `0` after the run, from the trap's restore.

If the helper exits non-zero, the suite never starts. Stop and report.

- [ ] **Step 3: The Playwright run.**
```bash
docker compose exec -T php bin/console cache:pool:clear cache.rate_limiter altcha.replay.cache
(cd frontend && npm run e2e -- --reporter=list 2>&1 | tee "$SCRATCH/1287-playwright.log")
grep -E '^[[:space:]]+[0-9]+ (passed|failed|flaky|skipped)' "$SCRATCH/1287-playwright.log"
docker compose exec -T php printenv MAILER_FORCE_FALLBACK
```
Expected: `forced` in the log near the start, `127 passed`, and no `failed` or `skipped`, then `0`.

- [ ] **Step 4: Put the stack back.** Start the worker if `$SCRATCH/1287-running-before.txt` listed it (`docker compose start worker`). Then check that `docker compose ps --services --status running` matches that file.

- [ ] **Step 5: Gates (from `backend/`, in parallel, `infection:diff` after the native leg).** `composer cs`, `bin/console cache:warmup && composer stan`, `composer md`, `composer tramp`, `composer test:parallel`, `docker compose exec -T php composer test:parallel`, `composer infection:diff`. Also run PhpStorm inspections on the changed PHP files and scan the dev log. Expected: all green, and `infection:diff` kills every mutant on the changed line.

---

### Task 6: Review, PR, merge, close

- [ ] **Step 1:** One independent sonnet reviewer, read-only. Give it this plan, the issue and `git diff origin/develop...HEAD`. Attack points:
  - Can any e2e path still mail through the saved server?
  - Does every failure fail closed?
  - Does the teardown always restore?
  - Is it bash 3.2 safe?
  - CLAUDE.md compliance.

  Fix what it finds in its own commit.
- [ ] **Step 2:** Push, then open the PR. The body gives the problem, the switch, both entry points, CI, Task 5's results and the review, and ends `Closes #1287`. Check `closingIssuesReferences` once, and re-save the body once if it is empty.
- [ ] **Step 3:** Watch CI with one Monitor, and merge with `gh pr merge --merge` when it is green (never `--auto`). If #1287 is still open, close it by hand with `--reason completed` and a comment naming the PR and the merge SHA.
