import type { HLJSApi, LanguageFn } from 'highlight.js';

/**
 * Syntax-highlights reader code blocks (issue #473). `EntrySanitizer` strips the
 * publisher's `class="language-*"` hint, so the language is detected from the code
 * itself with `highlightAuto`. highlight.js and a curated language set load lazily,
 * only once an article actually carries a code block; the `hljs` class hljs adds
 * doubles as the idempotency marker across re-renders and the Reader/Original toggle.
 *
 * The block is a `<pre>`. Some feeds wrap the code in a `<code>` child, others (e.g.
 * publisher-pretokenised markup whose classes the sanitiser stripped) put the tokens
 * straight in the `<pre>`; either way the `<pre>` is the unit, so highlight its
 * `<code>` child when present and the `<pre>` itself otherwise.
 */
const HIGHLIGHTED = 'hljs';

// A multi-line block gives the detector enough shape to trust. A lone line only
// earns highlighting once it is long enough that the guess is not a coin flip;
// below this a trivial fragment (`const _ = 1;`) is left as plain text (#473).
const MIN_ONE_LINER_CHARS = 25;

// A small set sharpens detection and keeps the lazy chunk lean: a one- or two-token
// snippet has fewer wrong languages to be mistaken for.
const LANGUAGE_LOADERS: Record<string, () => Promise<{ default: LanguageFn }>> = {
  javascript: () => import('highlight.js/lib/languages/javascript'),
  typescript: () => import('highlight.js/lib/languages/typescript'),
  php: () => import('highlight.js/lib/languages/php'),
  python: () => import('highlight.js/lib/languages/python'),
  css: () => import('highlight.js/lib/languages/css'),
  xml: () => import('highlight.js/lib/languages/xml'),
  json: () => import('highlight.js/lib/languages/json'),
  bash: () => import('highlight.js/lib/languages/bash'),
  sql: () => import('highlight.js/lib/languages/sql'),
};

let enginePromise: Promise<HLJSApi> | null = null;

export async function highlightCodeBlocks(host: HTMLElement): Promise<void> {
  const pending = Array.from(host.querySelectorAll<HTMLElement>('pre'))
    .map(codeContainer)
    .filter(worthHighlighting);
  if (pending.length === 0) return;
  const hljs = await loadEngine();
  for (const block of pending) {
    if (!block.isConnected || block.classList.contains(HIGHLIGHTED)) continue;
    block.innerHTML = hljs.highlightAuto(block.textContent ?? '').value;
    block.classList.add(HIGHLIGHTED);
  }
}

function codeContainer(pre: HTMLElement): HTMLElement {
  return pre.querySelector(':scope > code') ?? pre;
}

function worthHighlighting(block: HTMLElement): boolean {
  if (block.classList.contains(HIGHLIGHTED)) return false;
  const code = (block.textContent ?? '').trim();
  const lines = code.split('\n').filter((line) => line.trim().length > 0).length;
  if (lines >= 2) return true;
  return code.length >= MIN_ONE_LINER_CHARS;
}

function loadEngine(): Promise<HLJSApi> {
  return (enginePromise ??= registerCuratedLanguages());
}

async function registerCuratedLanguages(): Promise<HLJSApi> {
  const { default: hljs } = await import('highlight.js/lib/core');
  const loaded = await Promise.all(
    Object.entries(LANGUAGE_LOADERS).map(
      async ([name, load]) => [name, (await load()).default] as const,
    ),
  );
  for (const [name, language] of loaded) hljs.registerLanguage(name, language);
  return hljs;
}
