// Helpers shared by the screenshot and tour scripts.

export const BASE = process.env.SHOT_BASE_URL ?? 'http://localhost:4200';

// The magazine style is a server preference; answer the profile with airy instead of saving it.
export async function stubAiryStyle(pageOrContext) {
  await pageOrContext.route(/\/api\/me$/, async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    const response = await route.fetch();
    const profile = await response.json();
    await route.fulfill({
      response,
      json: { ...profile, preferences: { ...profile.preferences, magazineStyle: 'airy' } },
    });
  });
}

async function dismissPasskeyOffer(page) {
  const offer = page.getByRole('dialog', { name: 'Set up a passkey?' });
  if (await offer.isVisible().catch(() => false)) {
    await offer.getByRole('button', { name: 'Not now', exact: true }).click();
  }
}

export async function signIn(page) {
  await page.goto(`${BASE}/login`);
  await page.locator('input[type=email]').fill(process.env.SHOT_EMAIL);
  await page.locator('input[type=password]').fill(process.env.SHOT_PASSWORD);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await page.waitForURL((url) => !url.pathname.startsWith('/login'));
  await dismissPasskeyOffer(page);
}
