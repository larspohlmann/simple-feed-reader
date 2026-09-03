import { readFileSync } from 'node:fs';
import { join } from 'node:path';

/**
 * #497: innerHTML-injected body images leak the app origin (403s from
 * hotlink-protecting CDNs) since a per-img referrerpolicy gets stripped;
 * this document-wide meta is the only fix -- the CI tripwire that it stays.
 */
describe('index.html referrer policy', () => {
  const html = readFileSync(join(__dirname, 'index.html'), 'utf8');
  const document = new DOMParser().parseFromString(html, 'text/html');

  it('declares a document-wide no-referrer policy', () => {
    const policy = document.querySelector('meta[name="referrer"]');

    expect(policy?.getAttribute('content')).toBe('no-referrer');
  });
});
