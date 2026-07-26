import { APIRequestContext, Page, expect, test } from '@playwright/test';

// The onboarding journey, end to end against the live Docker stack: a brand-new
// user registers, confirms their address, is approved, signs in, and — with
// zero subscriptions and a populated catalog — is sent to the picker. From
// there they either subscribe (and land in a reader carrying their new tags) or
// skip (and land in the reader with a way back).
//
// The flow is real, so it depends on real infrastructure. Every dependency the
// test cannot conjure — Mailpit to read the confirmation link, the seeded admin
// to approve the account, a browser that can solve the registration ALTCHA —
// becomes a clean `test.skip` rather than a flake, matching the convention in
// reader-smoke.spec.ts: an unavailable precondition is skipped, not failed.

const MAILPIT_API = process.env.E2E_MAILPIT_API ?? 'http://localhost:8025/api/v1';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

/** A password comfortably over the 12-char minimum the register form enforces. */
const PASSWORD = 'onboarding-e2e-password-123';

interface MailpitMessage {
  ID: string;
  To: { Address: string }[];
}

/** Newest-first search for a message addressed to `email`, or null if none yet. */
async function findMessageId(request: APIRequestContext, email: string): Promise<string | null> {
  const response = await request.get(
    `${MAILPIT_API}/search?query=${encodeURIComponent(`to:${email}`)}&limit=5`,
  );
  if (!response.ok()) return null;
  const body = (await response.json()) as { messages?: MailpitMessage[] };
  const match = body.messages?.find((m) => m.To.some((t) => t.Address === email));
  return match?.ID ?? null;
}

/** Poll Mailpit until the confirmation mail arrives, then pull the verify link
 *  out of its plain-text body. Returns null if the mail never lands (the mailer
 *  defers SMTP past the response, so a short poll is expected). */
async function fetchVerificationUrl(
  request: APIRequestContext,
  email: string,
): Promise<string | null> {
  for (let attempt = 0; attempt < 20; attempt++) {
    const id = await findMessageId(request, email);
    if (id) {
      const message = await request.get(`${MAILPIT_API}/message/${id}`);
      const text = ((await message.json()) as { Text: string }).Text;
      const link = /https?:\/\/\S+\/verify-email\?token=\S+/.exec(text)?.[0];
      if (link) return link;
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  return null;
}

/** Approve the just-verified account through the admin API (the same POST the
 *  admin console drives), so it becomes Active and can sign in. Returns false
 *  when the seeded admin is unavailable, so the caller can skip cleanly. */
async function approveAccount(request: APIRequestContext, email: string): Promise<boolean> {
  const login = await request.post('/api/auth/login', {
    data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD },
  });
  if (!login.ok()) return false;
  const token = ((await login.json()) as { token: string }).token;
  const authorization = { Authorization: `Bearer ${token}` };

  const list = await request.get('/api/admin/users?status=pending_approval', {
    headers: authorization,
  });
  if (!list.ok()) return false;
  const users = ((await list.json()) as { users: { id: number; email: string }[] }).users;
  const pending = users.find((u) => u.email === email);
  if (!pending) return false;

  const approve = await request.post(`/api/admin/users/${pending.id}/approve`, {
    headers: authorization,
  });
  return approve.ok();
}

/**
 * Register a brand-new user through the real form, confirm their email via the
 * link Mailpit received, have the seeded admin approve the account, then sign
 * in — leaving the page authenticated with zero subscriptions. Skips (rather
 * than fails) the moment a precondition it cannot satisfy is missing.
 */
async function registerAndVerify(page: Page): Promise<void> {
  const request = page.request;
  const email = `onboarding-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.com`;

  // Mailpit is the only way to read the confirmation link; without it there is
  // no journey to test.
  const mailpitUp = await request
    .get(`${MAILPIT_API}/messages?limit=1`)
    .then((r) => r.ok())
    .catch(() => false);
  test.skip(!mailpitUp, `Mailpit unreachable at ${MAILPIT_API}`);

  // --- Register. The submit runs a browser-side ALTCHA proof-of-work, so the
  // confirmation can take a few seconds; wait for the done state, not instantly.
  await page.goto('/register');
  await page.locator('input[type=email]').fill(email);
  await page.locator('input[type=password]').fill(PASSWORD);
  await page.getByRole('button', { name: 'Create account' }).click();

  const registered = page.getByText(/Check your email/i);
  const registerError = page.getByRole('alert');
  await expect(registered.or(registerError)).toBeVisible({ timeout: 20_000 });
  test.skip(
    !(await registered.isVisible()),
    'registration did not complete (ALTCHA or rate limit) — cannot reach onboarding',
  );

  // --- Confirm the address via the emailed link.
  const verifyUrl = await fetchVerificationUrl(request, email);
  test.skip(!verifyUrl, 'confirmation email never arrived in Mailpit');
  await page.goto(verifyUrl!);
  await expect(page.getByText(/Your email is confirmed/i)).toBeVisible({ timeout: 15_000 });

  // --- Approve, so the account may sign in. Needs the seeded admin.
  const approved = await approveAccount(request, email);
  test.skip(!approved, 'seeded admin unavailable to approve the account (run app:e2e:seed-admin)');

  // --- Sign in. The reader shell then redirects a zero-subscription user with a
  // populated catalog to the picker.
  await page.goto('/login');
  await page.locator('input[type=email]').fill(email);
  await page.locator('input[type=password]').fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
}

test('a new user is sent to the picker and lands in a reader with their tags', async ({ page }) => {
  const failures: string[] = [];
  // The picker must make NO request to a publisher domain — favicons come from
  // our own origin (/api/catalog/feeds/{id}/favicon), cached or monogram. This
  // is the assertion that keeps it so. Attached from the start so it catches
  // every favicon load the picker fires, but scoped to requests made WHILE the
  // picker is on screen: sign-in transiently mounts the reader (whose header
  // loads the user's gravatar avatar) before the redirect to /discover, and
  // that benign external avatar request is not the publisher-domain favicon
  // fetch this guards against.
  page.on('request', (request) => {
    if (!page.url().includes('/discover')) return;
    const url = new URL(request.url());
    if (!['localhost', '127.0.0.1'].includes(url.hostname)) failures.push(request.url());
  });

  await registerAndVerify(page);

  await expect(page).toHaveURL(/\/discover$/);

  const technology = page.getByRole('group', { name: 'Technology' });
  await technology.getByRole('button', { name: 'Select all' }).click();

  const science = page.getByRole('group', { name: 'Science' });
  await science.getByRole('button', { name: 'Select all' }).click();

  await page.getByTestId('subscribe').click();

  await expect(page).toHaveURL(/\/$/);
  // Scope to the sidebar: once the post-onboarding sweep lands, "Technology" and
  // "Science" also appear as entry tag-pills, and the sidebar link's own name
  // carries the unread count ("Technology 65"). The sidebar tag link is the one
  // that proves the subscription's tags exist.
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  await expect(sidebar.getByRole('link', { name: 'Technology' })).toBeVisible();
  await expect(sidebar.getByRole('link', { name: 'Science' })).toBeVisible();

  expect(failures).toEqual([]);
});

test('skipping goes to the reader and leaves a way back', async ({ page }) => {
  await registerAndVerify(page);
  await expect(page).toHaveURL(/\/discover$/);

  await page.getByRole('button', { name: 'Skip for now' }).click();

  await expect(page).toHaveURL(/\/$/);
  await expect(page.getByRole('link', { name: /Browse suggested feeds/ })).toBeVisible();

  await page.reload();
  await expect(page).toHaveURL(/\/$/);
});
