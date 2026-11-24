import { readFileSync } from 'fs';
import { join } from 'path';
import { listFiles } from '../../../testing/list-files';

const READER = join(__dirname, '..');

const ALLOWED: Record<string, string> = {
  'shell/sidebar/sidebar-foot.component.html':
    'release notes and GitHub links, never a social post',
};

function unroutedNewTabAnchors(html: string): string[] {
  return (html.match(/<a\b[^>]*>/g) ?? []).filter(
    (tag) => tag.includes('target="_blank"') && !tag.includes('appOwnInstanceHref'),
  );
}

describe('outbound reader links', () => {
  it('open through the own-instance rule', () => {
    const offenders = listFiles(READER, (path) => path.endsWith('.html'))
      .filter((path) => !(path in ALLOWED))
      .filter((path) => unroutedNewTabAnchors(readFileSync(join(READER, path), 'utf8')).length > 0);

    expect(offenders).toEqual([]);
  });
});
