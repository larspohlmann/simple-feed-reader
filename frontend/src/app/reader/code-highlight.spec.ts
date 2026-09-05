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

  it('leaves a one-line snippet unstyled', async () => {
    const el = host(block('const distance = Math.hypot(deltaX, deltaY);'));

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
