import { readdirSync, readFileSync } from 'fs';
import { join, relative } from 'path';

const READER = join(__dirname, '..');

const ALLOWED: Record<string, string> = {
  'shell/sidebar/sidebar-foot.component.html':
    'release notes and GitHub links, never a social post',
  'article/article-page/article-page.component.html':
    'binds openUrl, which entryOpenUrl already resolved',
};

function templatesUnder(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((item) => {
    const path = join(directory, item.name);
    if (item.isDirectory()) return templatesUnder(path);
    return item.name.endsWith('.html') ? [path] : [];
  });
}

function unroutedNewTabAnchors(html: string): string[] {
  return (html.match(/<a\b[^>]*>/g) ?? []).filter(
    (tag) => tag.includes('target="_blank"') && !tag.includes('appOwnInstanceHref'),
  );
}

describe('outbound reader links', () => {
  it('open through the own-instance rule', () => {
    const offenders = templatesUnder(READER)
      .map((path) => relative(READER, path))
      .filter((path) => !(path in ALLOWED))
      .filter((path) => unroutedNewTabAnchors(readFileSync(join(READER, path), 'utf8')).length > 0);

    expect(offenders).toEqual([]);
  });
});
