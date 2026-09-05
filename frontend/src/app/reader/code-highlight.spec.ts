import { highlightCodeBlocks } from './code-highlight';

const host = (html: string): HTMLElement => {
  const el = document.createElement('div');
  el.innerHTML = html;
  document.body.appendChild(el);
  return el;
};

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
    const el = host(block(MULTILINE));

    await highlightCodeBlocks(el);

    const code = el.querySelector('pre > code');
    expect(code?.classList.contains('hljs')).toBe(true);
    expect(code?.querySelector('[class^="hljs-"]')).not.toBeNull();
  });

  it('highlights a bare <pre> block that has no <code> child', async () => {
    const el = host(
      '<pre><span>final class</span> CreateBooksTable {\n    public function up(): void {\n        return;\n    }\n}</pre>',
    );

    await highlightCodeBlocks(el);

    const pre = el.querySelector('pre');
    expect(pre?.classList.contains('hljs')).toBe(true);
    expect(pre?.querySelector('[class^="hljs-"]')).not.toBeNull();
  });

  it('highlights a short multi-line block', async () => {
    const el = host(block('if (a < b) {\n  swap();\n}'));

    await highlightCodeBlocks(el);

    const code = el.querySelector('pre > code');
    expect(code?.classList.contains('hljs')).toBe(true);
    expect(code?.querySelector('[class^="hljs-"]')).not.toBeNull();
  });

  it('highlights a substantial one-liner', async () => {
    const el = host(block("const slug = str_replace(' ', '-', title);"));

    await highlightCodeBlocks(el);

    const code = el.querySelector('pre > code');
    expect(code?.classList.contains('hljs')).toBe(true);
  });

  it('leaves a trivial one-line snippet unstyled', async () => {
    const el = host(block('const _ = 1;'));

    await highlightCodeBlocks(el);

    const code = el.querySelector('pre > code');
    expect(code?.classList.contains('hljs')).toBe(false);
    expect(code?.querySelector('[class^="hljs-"]')).toBeNull();
  });

  it('does not re-process an already-highlighted block', async () => {
    const el = host(block(MULTILINE));
    await highlightCodeBlocks(el);
    const code = el.querySelector('pre > code');
    const afterFirstPass = code?.innerHTML;

    await highlightCodeBlocks(el);

    expect(code?.innerHTML).toBe(afterFirstPass);
    expect(code?.querySelectorAll('code')).toHaveLength(0);
  });

  it('resolves without error when there is no code block', async () => {
    const el = host('<p>No code here.</p>');

    await expect(highlightCodeBlocks(el)).resolves.toBeUndefined();
  });
});
