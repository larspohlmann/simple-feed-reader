import { articleHost } from '../../../../testing/article-host-testing';
import { linkBareUrls } from './bare-url-links';

const NO_BREAK_SPACE = String.fromCharCode(0xa0);

const linked = (html: string): HTMLElement => articleHost(html, linkBareUrls);

const hrefs = (host: HTMLElement): string[] =>
  Array.from(host.querySelectorAll('a')).map((link) => link.getAttribute('href') ?? '');

describe('linkBareUrls', () => {
  it('links a URL that is the whole paragraph', () => {
    const host = linked('<p>https://soundcloud.com/radiozora/sets/sangoma-records-series</p>');

    expect(host.innerHTML).toBe(
      '<p><a href="https://soundcloud.com/radiozora/sets/sangoma-records-series">' +
        'https://soundcloud.com/radiozora/sets/sangoma-records-series</a></p>',
    );
  });

  it('links every URL in a line and keeps the text between them', () => {
    const host = linked(
      `<p>https://soundcloud.com/sg4ry |${NO_BREAK_SPACE}https://soundcloud.com/sangoma-recs</p>`,
    );

    expect(hrefs(host)).toEqual([
      'https://soundcloud.com/sg4ry',
      'https://soundcloud.com/sangoma-recs',
    ]);
    expect(host.textContent).toBe(
      `https://soundcloud.com/sg4ry |${NO_BREAK_SPACE}https://soundcloud.com/sangoma-recs`,
    );
  });

  it('leaves the sentence punctuation that trails a URL outside the link', () => {
    const host = linked('<p>See (http://x.test/a?b=1), then http://x.test/c.</p>');

    expect(hrefs(host)).toEqual(['http://x.test/a?b=1', 'http://x.test/c']);
    expect(host.textContent).toBe('See (http://x.test/a?b=1), then http://x.test/c.');
  });

  it('keeps punctuation that sits inside a URL', () => {
    const host = linked('<p>http://x.test/a.b/c,d end</p>');

    expect(hrefs(host)).toEqual(['http://x.test/a.b/c,d']);
  });

  it('leaves a URL that already sits in a link, in code or in a code block', () => {
    const html =
      '<p><a href="http://x.test/a">http://x.test/<b>a</b></a></p>' +
      '<p><code>http://x.test/b</code></p><pre>http://x.test/c</pre>';

    expect(linked(html).innerHTML).toBe(html);
  });

  it('leaves text without an http URL alone', () => {
    const html = '<p>Mail ftp://x.test or www.x.test</p>';

    expect(linked(html).innerHTML).toBe(html);
  });

  it('links nothing twice when it runs again', () => {
    const host = linked('<p>http://x.test/a</p>');
    linkBareUrls(host);

    expect(host.querySelectorAll('a')).toHaveLength(1);
  });
});
