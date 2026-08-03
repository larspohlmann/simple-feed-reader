import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

// Playwright global setup: provision the Docker dev database the smokes run
// against, the same fixture steps backend/bin/e2e.sh runs for the black-box
// suite. Kept in step with that script so `npm run e2e` and `composer e2e` see
// the same fixtures.
//
//   1. Purge the throwaway accounts a previous run left behind, so `npm run e2e`
//      stops growing the database (#184). The purge keeps the seeded admin.
//   2. Seed (or promote) the admin the smokes sign in as.
//   3. Give that admin a subscription, so the reader shell mounts instead of
//      redirecting to the onboarding picker — without it the reader, magazine
//      and sidebar smokes time out waiting for reader chrome (#222).
//
// Purge before the suite, not after: an interrupted run still gets cleaned up on
// the next one, and a failed run leaves its data in place for inspection.
//
// Best-effort: on a host where Docker is unavailable the specs skip their
// infrastructure-dependent steps cleanly, so a missing stack must not abort the
// whole run. A warning is enough; it must never throw.
const FIXTURE_COMMANDS: readonly string[][] = [
  ['app:e2e:purge-users'],
  ['app:e2e:seed-admin'],
  ['app:e2e:seed-admin-subscription'],
];

export default function globalSetup(): void {
  const composeFile = resolve(__dirname, '..', '..', 'docker-compose.yml');

  for (const consoleArgs of FIXTURE_COMMANDS) {
    try {
      execFileSync(
        'docker',
        ['compose', '-f', composeFile, 'exec', '-T', 'php', 'bin/console', ...consoleArgs],
        { stdio: 'inherit' },
      );
    } catch (error) {
      const reason = error instanceof Error ? error.message : String(error);
      console.warn(
        `[global-setup] Skipping e2e fixture step "${consoleArgs.join(' ')}": ${reason}`,
      );
    }
  }
}
