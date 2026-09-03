// e2e/article-audio-redirect.spec.ts
import { test, expect, Page } from '@playwright/test';
import { createServer, IncomingMessage, Server, ServerResponse } from 'node:http';
import type { AddressInfo } from 'node:net';

// Same seeded admin as reader-smoke.spec.ts (`bin/console app:e2e:seed-admin`).
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

/**
 * The shape a Substack audio post ships (#786): the reader body keeps the
 * publisher's own `<audio>`, whose source answers a 307 to a signed CDN URL
 * that is served as `binary/octet-stream` under `nosniff`. The reader must
 * play through that redirect with no hint about the content type — the signed
 * landing expires, so the body can only ever carry the redirecting URL.
 *
 * Both hops come from a throwaway HTTP server on the loopback interface: a
 * `page.route()` can answer the first hop, but Chromium follows a redirect
 * inside the network stack and Playwright never sees the second request, so a
 * routed landing is never served. A real server is the only faithful stub.
 */
const SOURCE_PATH = '/api/v1/audio/upload/7adcfe96/src';
const LANDING_PATH = '/video_upload/post/1/7adcfe96/transcoded.wav';
const LANDING_QUERY = '?post_id=1&relation=embed&Expires=1788502848&Key-Pair-Id=K&Signature=s';

const ENTRY = {
  id: 1,
  title: 'Audio version of the essay',
  url: 'https://fixtures.invalid/p/audio-version',
  author: null,
  summary: 'summary',
  contentHtml: '<p>Feed body.</p>',
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  publishedAt: '2026-08-01T12:50:34+00:00',
  createdAt: '2026-08-01T12:50:34+00:00',
  subscriptionId: 1,
  source: 'Fixture source',
  faviconUrl: null,
  isHidden: false,
  isFavorite: false,
  isKept: false,
};

/** One second of 8 kHz 8-bit mono silence: a container every browser decodes. */
function silentWav(): Buffer {
  const sampleRate = 8000;
  const samples = sampleRate;
  const header = Buffer.alloc(44);
  header.write('RIFF', 0);
  header.writeUInt32LE(36 + samples, 4);
  header.write('WAVE', 8);
  header.write('fmt ', 12);
  header.writeUInt32LE(16, 16);
  header.writeUInt16LE(1, 20);
  header.writeUInt16LE(1, 22);
  header.writeUInt32LE(sampleRate, 24);
  header.writeUInt32LE(sampleRate, 28);
  header.writeUInt16LE(1, 32);
  header.writeUInt16LE(8, 34);
  header.write('data', 36);
  header.writeUInt32LE(samples, 40);
  return Buffer.concat([header, Buffer.alloc(samples, 128)]);
}

/** The publisher's redirect (no CORS header, like Substack's) and the CDN landing (byte ranges, octet-stream, nosniff). */
function audioOrigin(): Promise<{ server: Server; origin: string }> {
  const file = silentWav();
  const server = createServer((request: IncomingMessage, response: ServerResponse) => {
    const url = new URL(request.url ?? '/', 'http://audio.local');
    if (url.pathname === SOURCE_PATH) {
      response.writeHead(307, { location: `${origin(server)}${LANDING_PATH}${LANDING_QUERY}` });
      return response.end();
    }
    if (url.pathname !== LANDING_PATH) {
      response.writeHead(404);
      return response.end();
    }
    const range = /^bytes=(\d+)-(\d*)$/.exec(request.headers.range ?? '');
    const start = range ? Number(range[1]) : 0;
    const end = range && range[2] !== '' ? Number(range[2]) : file.length - 1;
    response.writeHead(range ? 206 : 200, {
      'content-type': 'binary/octet-stream',
      'x-content-type-options': 'nosniff',
      'accept-ranges': 'bytes',
      'access-control-allow-origin': '*',
      'content-length': end - start + 1,
      ...(range ? { 'content-range': `bytes ${start}-${end}/${file.length}` } : {}),
    });
    response.end(file.subarray(start, end + 1));
  });
  return new Promise((resolve) =>
    server.listen(0, '127.0.0.1', () => resolve({ server, origin: origin(server) })),
  );
}

function origin(server: Server): string {
  const { port } = server.address() as AddressInfo;
  return `http://127.0.0.1:${port}`;
}

async function signInAsAdmin(page: Page): Promise<boolean> {
  await page.addInitScript(() => localStorage.setItem('sfr.layout', 'list'));
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

/** Serve one article whose reader body carries the publisher's player. */
async function stubArticle(page: Page, audioSrc: string): Promise<void> {
  const body =
    '<p>For people who prefer to listen or are visually impaired or are multitasking.</p>' +
    `<p><audio src="${audioSrc}" preload="none" controls>Audio playback is not supported.</audio></p>` +
    '<p>Thank you for subscribing.</p>';
  await page.route(
    (url) => /^\/api\/entries\/\d+\/reader$/.test(url.pathname),
    async (route) =>
      route.fulfill({
        status: 200,
        json: {
          status: 'ok',
          url: ENTRY.url,
          title: ENTRY.title,
          byline: null,
          siteName: 'Fixture source',
          contentHtml: body,
          excerpt: null,
          paywalled: false,
          originalHero: null,
          extractedAt: '2026-08-01T12:50:34+00:00',
        },
      }),
  );
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { entries: [ENTRY], nextCursor: null } });
    },
  );
}

test('the reader plays an audio source that redirects to a signed octet-stream landing', async ({
  page,
}) => {
  const { server, origin: audio } = await audioOrigin();
  try {
    await stubArticle(page, `${audio}${SOURCE_PATH}`);
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await page.getByText(ENTRY.title, { exact: false }).first().click();
    const player = page.locator('app-reader-view .content audio');
    await expect(player).toBeVisible();
    await expect(player).toHaveAttribute('controls', '');

    const playback = await player.evaluate(async (element: HTMLAudioElement) => {
      element.muted = true;
      await element.play();
      await new Promise<void>((resolve, reject) => {
        const deadline = Date.now() + 5000;
        const tick = () => {
          if (element.error) reject(new Error(element.error.message));
          else if (element.currentTime > 0) resolve();
          else if (Date.now() > deadline) reject(new Error('playback never advanced'));
          else setTimeout(tick, 50);
        };
        tick();
      });
      return { readyState: element.readyState, duration: element.duration };
    });

    expect(playback.readyState).toBeGreaterThanOrEqual(2);
    expect(playback.duration).toBeGreaterThan(0);
  } finally {
    server.close();
  }
});
