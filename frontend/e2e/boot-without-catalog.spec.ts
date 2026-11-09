import { expect, test } from '@playwright/test';
import { signInAsAdmin } from './support/auth';

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
  const subscriptions = page.waitForResponse(
    (response) => new URL(response.url()).pathname === '/api/subscriptions',
  );
  await page.reload();
  const profile = (await (await me).json()) as { catalog: { hasEntries: boolean } };
  await subscriptions;
  await expect(page.getByRole('navigation', { name: 'Feeds' })).toBeVisible();

  expect(typeof profile.catalog.hasEntries).toBe('boolean');
  expect(catalogRequests).toEqual([]);
});
