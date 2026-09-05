import type { HLJSApi, LanguageFn } from 'highlight.js';

/**
 * Syntax-highlights reader code blocks (issue #473). `EntrySanitizer` strips the
 * publisher's `class="language-*"` hint, so the language is detected from the code
 * itself with `highlightAuto`. highlight.js and a curated language set load lazily,
 * only once an article actually carries a `<pre><code>`; the `hljs` class hljs adds
 * doubles as the idempotency marker across re-renders and the Reader/Original toggle.
 */
const HIGHLIGHTED = 'hljs';
const MIN_LINES = 2;
const MIN_CHARS = 40;

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
  const pending = Array.from(host.querySelectorAll<HTMLElement>('pre > code')).filter(
    worthHighlighting,
  );
  if (pending.length === 0) return;
  const hljs = await loadEngine();
  for (const block of pending) {
    if (!block.isConnected || block.classList.contains(HIGHLIGHTED)) continue;
    block.innerHTML = hljs.highlightAuto(block.textContent ?? '').value;
    block.classList.add(HIGHLIGHTED);
  }
}

function worthHighlighting(block: HTMLElement): boolean {
  if (block.classList.contains(HIGHLIGHTED)) return false;
  const code = (block.textContent ?? '').trim();
  if (code.length < MIN_CHARS) return false;
  return code.split('\n').filter((line) => line.trim().length > 0).length >= MIN_LINES;
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
