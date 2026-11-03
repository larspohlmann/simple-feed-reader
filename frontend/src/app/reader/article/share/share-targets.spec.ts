import { existsSync } from 'node:fs';
import { join } from 'node:path';
import config from '../../../../../public/share-targets/share-targets.json';
import { ConfiguredTarget, SHARE_TARGETS, carriesText, shareUrl } from './share-targets';

/** The format the account stores hidden target ids in; `Preferences::TARGET_ID_PATTERN` on the server. */
const TARGET_ID_PATTERN = /^[a-z0-9-]{1,32}$/;

describe('share targets', () => {
  it('fills the url, the title and the text, encoded', () => {
    const content = {
      url: 'https://a.b/?q=1',
      title: 'A & B',
      text: 'A & B https://a.b/?q=1',
      quote: null,
    };

    expect(shareUrl('x?u={url}&t={title}&s={text}', content)).toBe(
      'x?u=https%3A%2F%2Fa.b%2F%3Fq%3D1&t=A%20%26%20B&s=A%20%26%20B%20https%3A%2F%2Fa.b%2F%3Fq%3D1',
    );
  });

  it('carries text through the system sheet, the clipboard and a {text} address only', () => {
    const byLabel = (label: string) =>
      SHARE_TARGETS.find(
        (target): target is ConfiguredTarget => target.kind !== 'divider' && target.label === label,
      )!;

    expect(carriesText({ kind: 'copy', id: 'copy' })).toBe(true);
    expect(carriesText(byLabel('E-Mail'))).toBe(true);
    expect(carriesText(byLabel('WhatsApp'))).toBe(true);
    expect(carriesText(byLabel('X'))).toBe(false);
    expect(carriesText(byLabel('Facebook'))).toBe(false);
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

  it('names every target with a unique, well-formed id', () => {
    const ids = (config as readonly { kind: string; id?: unknown }[]).flatMap((entry) =>
      entry.kind === 'divider' ? [] : [entry.id],
    );

    expect(ids.filter((id) => typeof id !== 'string' || !TARGET_ID_PATTERN.test(id))).toStrictEqual(
      [],
    );
    expect(new Set(ids).size).toBe(ids.length);
  });
});
