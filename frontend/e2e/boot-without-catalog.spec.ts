import { expect, test } from '@playwright/test';
import { signInAsAdmin } from './support/auth';

// An admin boot used to fetch the whole catalog to decide the empty-catalog
// warning; /api/me now carries that answer (#2138).
test('a reader boot asks /api/me, not /api/catalog, whether the catalog has entries', async ({
  page,
}) => {
  const catalogRequests: string[] = [];
  page.on('request', (request) => {
    if (new URL(request.url()).pathname === '/api/catalog') catalogRequests.push(request.url());
  });

  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  const me = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/me');
  await page.reload();
  const profile = (await (await me).json()) as { catalog: { hasEntries: boolean } };
  await expect(page.getByRole('navigation', { name: 'Feeds' })).toBeVisible();
  await page.waitForLoadState('networkidle');

  expect(typeof profile.catalog.hasEntries).toBe('boolean');
  expect(catalogRequests).toEqual([]);
});
