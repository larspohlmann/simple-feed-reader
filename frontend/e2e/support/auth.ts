import { Page } from '@playwright/test';

/**
 * Put a token where the route guard looks, without a round trip.
 *
 * `authGuard` only asks `TokenStore.isAuthenticated()`, which is a presence
 * check on this one localStorage key — so a spec that stubs the API it needs
 * can reach a guarded route hermetically, with no Mailpit, no seeded admin and
 * no Docker. Specs that exercise the real login (auth-smoke, onboarding) must
 * keep doing so; this is for the ones testing what happens once you are in.
 */
export async function stubAuthToken(page: Page): Promise<void> {
  await page.route('**/api/setup/status', async (route) => {
    await route.fulfill({
      json: { needsSetup: false, mailEnabled: true, passkeySignInAvailable: false },
    });
  });
  // Carries a `userId` claim: without one, the reader holds its first list load for `/api/me`.
  await page.addInitScript(() =>
    localStorage.setItem('sfr.jwt', 'eyJhbGciOiJub25lIn0.eyJ1c2VySWQiOjF9.stub-signature'),
  );
}
