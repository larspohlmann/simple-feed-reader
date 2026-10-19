import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { SHARE_TARGETS, shareGlyph, shareUrl } from './share-targets';

describe('share targets', () => {
  it('fills the url, the title and both together, encoded', () => {
    expect(shareUrl('x?u={url}&t={title}&s={text}', 'https://a.b/?q=1', 'A & B')).toBe(
      'x?u=https%3A%2F%2Fa.b%2F%3Fq%3D1&t=A%20%26%20B&s=A%20%26%20B%20https%3A%2F%2Fa.b%2F%3Fq%3D1',
    );
  });

  it('shares the url alone as the text when there is no title', () => {
    expect(shareUrl('{text}', 'https://a.b', '')).toBe('https%3A%2F%2Fa.b');
  });

  it('uses the iOS glyph on Apple devices', () => {
    expect(shareGlyph('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0)')).toBe('ios_share');
    expect(shareGlyph('Mozilla/5.0 (Linux; Android 15)')).toBe('share');
  });

  it('names only icon files that exist next to the config', () => {
    const folder = join(__dirname, '../../../../../public/share-targets');
    const missing = SHARE_TARGETS.flatMap((target) =>
      target.kind === 'link' && target.icon && !existsSync(join(folder, target.icon))
        ? [target.icon]
        : [],
    );

    expect(missing).toEqual([]);
  });
});
