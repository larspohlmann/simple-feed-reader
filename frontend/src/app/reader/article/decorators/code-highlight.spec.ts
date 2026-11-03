import { attachedArticleHost } from '../../../../testing/article-host-testing';
import { highlightCodeBlocks } from './code-highlight';

const block = (code: string): string => `<pre><code>${code}</code></pre>`;

const MULTILINE = [
  'const greeting = "hello";',
  'function shout(text) {',
  '  return text.toUpperCase();',
  '}',
].join('\n');

describe('highlightCodeBlocks', () => {
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('highlights a multi-line code block', async () => {
    const element = attachedArticleHost(block(MULTILINE));

    await highlightCodeBlocks(element);

    const code = element.querySelector('pre > code');
    expect(code?.classList.contains('hljs')).toBe(true);
    expect(code?.querySelector('[class^="hljs-"]')).not.toBeNull();
    expect(element.querySelector('pre')?.classList.contains('reader-preformatted')).toBe(false);
  });

  it('highlights a bare <pre> block that has no <code> child', async () => {
    const element = attachedArticleHost(
      '<pre><span>final class</span> CreateBooksTable {\n    public function up(): void {\n        return;\n    }\n}</pre>',
    );

    await highlightCodeBlocks(element);

    const pre = element.querySelector('pre');
    expect(pre?.classList.contains('hljs')).toBe(true);
    expect(pre?.querySelector('[class^="hljs-"]')).not.toBeNull();
  });

  it('highlights a short multi-line block', async () => {
    const element = attachedArticleHost(block('if (a < b) {\n  swap();\n}'));

    await highlightCodeBlocks(element);

    const code = element.querySelector('pre > code');
    expect(code?.classList.contains('hljs')).toBe(true);
    expect(code?.querySelector('[class^="hljs-"]')).not.toBeNull();
  });

  it('highlights a substantial one-liner', async () => {
    const element = attachedArticleHost(block("const slug = str_replace(' ', '-', title);"));

    await highlightCodeBlocks(element);

    const code = element.querySelector('pre > code');
    expect(code?.classList.contains('hljs')).toBe(true);
  });

  it('leaves a trivial one-line snippet unstyled', async () => {
    const element = attachedArticleHost(block('const _ = 1;'));

    await highlightCodeBlocks(element);

    const code = element.querySelector('pre > code');
    expect(code?.classList.contains('hljs')).toBe(false);
    expect(code?.querySelector('[class^="hljs-"]')).toBeNull();
  });

  it('leaves a <pre> broken with <br> alone: that is preformatted text, not code', async () => {
    const element = attachedArticleHost(
      block(
        'Themen/ Stichworte:<br><br>Nazivergangenheit des Vaters: 100.000 Mann Heer<br>Giftskandal in Hamburg, Rudern, Notstandsgesetze',
      ),
    );

    await highlightCodeBlocks(element);

    const pre = element.querySelector('pre');
    expect(pre?.querySelectorAll('br')).toHaveLength(3);
    expect(pre?.classList.contains('reader-preformatted')).toBe(true);
  });

  it('leaves a <pre> holding a link alone and keeps the link', async () => {
    const element = attachedArticleHost(
      '<pre>Spenden für den Podcast: <a href="https://www.goodcrowd.org/podcast">https://www.goodcrowd.org/podcast</a></pre>',
    );

    await highlightCodeBlocks(element);

    const pre = element.querySelector('pre');
    expect(pre?.querySelector('a[href="https://www.goodcrowd.org/podcast"]')).not.toBeNull();
    expect(pre?.classList.contains('reader-preformatted')).toBe(true);
  });

  it('does not re-process an already-highlighted block', async () => {
    const element = attachedArticleHost(block(MULTILINE));
    await highlightCodeBlocks(element);
    const code = element.querySelector('pre > code');
    const afterFirstPass = code?.innerHTML;

    await highlightCodeBlocks(element);

    expect(code?.innerHTML).toBe(afterFirstPass);
    expect(code?.querySelectorAll('code')).toHaveLength(0);
  });

  it('resolves without error when there is no code block', async () => {
    const element = attachedArticleHost('<p>No code here.</p>');

    await expect(highlightCodeBlocks(element)).resolves.toBeUndefined();
  });
});
