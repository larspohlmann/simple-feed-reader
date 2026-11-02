/** Elements that start a line of their own; a selection that starts and ends in the same one is a single block. */
const BLOCKS = new Set([
  'ADDRESS',
  'ARTICLE',
  'ASIDE',
  'BLOCKQUOTE',
  'DD',
  'DIV',
  'DL',
  'DT',
  'FIGCAPTION',
  'FIGURE',
  'FOOTER',
  'H1',
  'H2',
  'H3',
  'H4',
  'H5',
  'H6',
  'HEADER',
  'HR',
  'LI',
  'OL',
  'P',
  'PRE',
  'SECTION',
  'TABLE',
  'TD',
  'TH',
  'TR',
  'UL',
]);
const SILENT = new Set(['BUTTON', 'NOSCRIPT', 'SCRIPT', 'STYLE', 'TEMPLATE']);

export interface SelectionText {
  readonly text: string;
  readonly singleBlock: boolean;
}

export function selectionText(range: Range): SelectionText {
  return {
    text: normalisedLines(textOf(range.cloneContents())),
    singleBlock: blockOf(range.startContainer) === blockOf(range.endContainer),
  };
}

export function wordsOf(text: string): string[] {
  return text.split(/\s+/).filter((word) => word !== '');
}

function textOf(node: Node): string {
  if (node.nodeType === Node.TEXT_NODE) return (node.textContent ?? '').replace(/\s+/g, ' ');
  if (!(node instanceof Element)) return childrenText(node);
  if (SILENT.has(node.tagName)) return '';
  if (node.tagName === 'BR') return '\n';
  return BLOCKS.has(node.tagName) ? `\n${childrenText(node)}\n` : childrenText(node);
}

function childrenText(node: Node): string {
  return Array.from(node.childNodes, textOf).join('');
}

function normalisedLines(text: string): string {
  return text
    .split('\n')
    .map((line) => line.replace(/\s+/g, ' ').trim())
    .filter((line) => line !== '')
    .join('\n');
}

function blockOf(node: Node): Element | null {
  let element = node instanceof Element ? node : node.parentElement;
  while (element !== null && !BLOCKS.has(element.tagName)) element = element.parentElement;
  return element;
}
