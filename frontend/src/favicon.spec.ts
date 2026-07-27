import { readFileSync } from 'node:fs';
import { join } from 'node:path';

/**
 * #137: Chrome and Brave showed the Angular scaffold's logo in the tab. Two
 * defects had to line up for that, and each gets an assertion here.
 *
 * The `.ico` was never actually rebranded -- #52 shipped the SVG mark but kept
 * the scaffold's `favicon.ico` "as a fallback", so the fallback pointed at the
 * wrong artwork. And Chromium resolves competing `<link rel="icon">` candidates
 * by taking the *last* one, so that stale `.ico` beat the branded SVG. Firefox
 * and Safari prefer the SVG, which is why it read as a Chrome-only bug.
 *
 * Asserting on the shipped assets rather than a rendered tab keeps this cheap
 * enough for every commit; a real tab icon is not observable from jsdom.
 */
describe('favicon', () => {
  const publicDir = join(__dirname, '..', 'public');
  const ico = readFileSync(join(publicDir, 'favicon.ico'));

  /** Parse the ICONDIR into one entry per frame. The header is 6 bytes, then 16 per entry. */
  const frames = (): { width: number; height: number; payload: Buffer }[] => {
    const count = ico.readUInt16LE(4);

    return Array.from({ length: count }, (_unused, index) => {
      const entry = 6 + index * 16;
      const size = ico.readUInt32LE(entry + 8);
      const offset = ico.readUInt32LE(entry + 12);

      return {
        // A zero byte means 256 in the ICO format; nothing here is that large.
        width: ico.readUInt8(entry) || 256,
        height: ico.readUInt8(entry + 1) || 256,
        payload: ico.subarray(offset, offset + size),
      };
    });
  };

  it('carries the brand mark, not the scaffold logo, in its 32px frame', () => {
    const brandMark = readFileSync(join(publicDir, 'favicon-32.png'));
    const frame = frames().find((candidate) => candidate.width === 32);

    expect(frame?.payload).toEqual(brandMark);
  });

  it('ships the sizes browsers and desktop shortcuts ask for', () => {
    expect(frames().map((frame) => `${frame.width}x${frame.height}`)).toEqual([
      '16x16',
      '32x32',
      '48x48',
    ]);
  });

  it('declares the ico before the svg so Chromium picks the vector mark', () => {
    const html = readFileSync(join(__dirname, 'index.html'), 'utf8');

    expect(html.indexOf('favicon.ico')).toBeLessThan(html.indexOf('favicon.svg'));
  });
});
