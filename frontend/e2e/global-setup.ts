import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

// Playwright global setup: purge the throwaway accounts a previous run left
// behind, so `npm run e2e` stops growing the Docker dev database (#184). The
// onboarding journey registers, confirms and approves a real user, which the
// unverified-account purge never reclaims — this command does.
//
// Runs before the suite, not after: an interrupted or failed run then still
// gets cleaned up on the next one, and a failed run leaves its data in place
// for inspection — the same reasoning as backend/bin/e2e.sh.
//
// Best-effort: on a host where Docker is unavailable the specs skip their
// infrastructure-dependent steps cleanly, so a missing stack must not abort the
// whole run. A warning is enough; it must never throw.
export default function globalSetup(): void {
  const composeFile = resolve(__dirname, '..', '..', 'docker-compose.yml');

  try {
    execFileSync(
      'docker',
      ['compose', '-f', composeFile, 'exec', '-T', 'php', 'bin/console', 'app:e2e:purge-users'],
      { stdio: 'inherit' },
    );
  } catch (error) {
    const reason = error instanceof Error ? error.message : String(error);
    console.warn(`[global-setup] Skipping e2e user purge: ${reason}`);
  }
}
