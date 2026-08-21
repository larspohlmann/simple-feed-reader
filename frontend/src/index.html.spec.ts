import { readFileSync } from 'node:fs';
import { join } from 'node:path';

/**
 * #497: article-body images are injected with `[innerHTML]` and carry no
 * `referrerpolicy` attribute, so they fall back to the document default and
 * leak the app origin in a `Referer` header — which hotlink-protecting CDNs
 * answer with a 403. Angular's innerHTML sanitizer and the backend
 * `EntrySanitizer` both strip a per-`img` `referrerpolicy`, so the only fix
 * that reaches those images is the document-wide policy declared in
 * `index.html`.
 *
 * That policy is one line with no other guard around it: delete it and every
 * body image silently starts leaking the origin again, with nothing in the CI
 * gate to notice. The real-browser proof lives in the issue; this test is the
 * gate-level tripwire that the declaration stays put.
 */
describe('index.html referrer policy', () => {
  const html = readFileSync(join(__dirname, 'index.html'), 'utf8');
  const document = new DOMParser().parseFromString(html, 'text/html');

  it('declares a document-wide no-referrer policy', () => {
    const policy = document.querySelector('meta[name="referrer"]');

    expect(policy?.getAttribute('content')).toBe('no-referrer');
  });
});
