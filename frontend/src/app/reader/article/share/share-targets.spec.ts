import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { SHARE_TARGETS, shareUrl } from './share-targets';

describe('share targets', () => {
  it('fills the url, the title and both together, encoded', () => {
    expect(shareUrl('x?u={url}&t={title}&s={text}', 'https://a.b/?q=1', 'A & B')).toBe(
      'x?u=https%3A%2F%2Fa.b%2F%3Fq%3D1&t=A%20%26%20B&s=A%20%26%20B%20https%3A%2F%2Fa.b%2F%3Fq%3D1',
    );
    expect(shareUrl('{text}', 'https://a.b', '')).toBe('https%3A%2F%2Fa.b');
  });

  it('names only icon files that exist next to the config', () => {
    const folder = join(__dirname, '../../../../../public/share-targets');
    const missing = SHARE_TARGETS.flatMap((target) =>
      target.kind !== 'divider' && target.icon && !existsSync(join(folder, target.icon))
        ? [target.icon]
        : [],
    );

    expect(missing).toEqual([]);
  });
});
