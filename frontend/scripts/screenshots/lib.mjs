// Helpers shared by the screenshot and tour scripts.

export const BASE = process.env.SHOT_BASE_URL ?? 'http://localhost:4200';
export const PHONE = {
  viewport: { width: 390, height: 760 },
  deviceScaleFactor: 2,
  isMobile: true,
  hasTouch: true,
};

export const articles = (page) => page.locator('app-entry-list article');

// An init script: the same reading settings on every page, whatever an earlier run left behind.
export function seedSettings({ theme, layout }) {
  localStorage.setItem('sfr.theme', theme);
  localStorage.setItem('sfr.lang', 'en');
  localStorage.setItem('sfr.readingFocus', 'false');
  localStorage.removeItem('sfr.sidebarHidden');
  localStorage.removeItem('sfr.textSize');
  localStorage.removeItem('sfr.unread-only');
  if (layout) localStorage.setItem('sfr.layout', layout);
}

export async function settle(page, milliseconds = 1500) {
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(milliseconds);
}

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

// A signed-in page for the setup scripts, with a helper that calls the API as the demo account.
export async function openApiSession(browser) {
  const page = await browser.newPage();
  await page.goto(`${BASE}/login`);
  await page.locator('input[type=email]').fill(process.env.SHOT_EMAIL);
  await page.locator('input[type=password]').fill(process.env.SHOT_PASSWORD);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await page.waitForURL((url) => !url.pathname.startsWith('/login'));
  return (method, path, body) =>
    page.evaluate(
      async ({ method, path, body }) => {
        const token = localStorage.getItem('sfr.jwt');
        const response = await fetch(path, {
          method,
          headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
          body: body === undefined ? undefined : JSON.stringify(body),
        });
        const text = await response.text();
        return { status: response.status, json: text ? JSON.parse(text) : null };
      },
      { method, path, body },
    );
}
