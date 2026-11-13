const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

const INLINE_TOKEN = new RegExp(
  [
    '`([^`]+)`',
    '\\*\\*(.+?)\\*\\*',
    '\\[([^\\]]+)\\]\\((https?://[^)\\s]+)\\)',
    '(https?://[^\\s<>]*[^\\s<>.,;:!?)\\]\'"])',
  ].join('|'),
  'g',
);

const PULL_REQUEST_BULLET = /^(.*) by @\S+ in (https:\/\/github\.com\/\S+\/pull\/(\d+))$/;
const LIST_ITEM = /^[*-] /;
const HEADING = /^#{2,3} /;

export function escapeHtml(text) {
  return text.replace(/[&<>"']/g, (character) => ESCAPES[character]);
}

function link(url, label) {
  return `<a href="${escapeHtml(url)}">${label}</a>`;
}

export function renderInline(text) {
  let html = '';
  let position = 0;
  for (const match of text.matchAll(INLINE_TOKEN)) {
    const [whole, code, bold, linkText, linkUrl, bareUrl] = match;
    html += escapeHtml(text.slice(position, match.index));
    if (code !== undefined) html += `<code>${escapeHtml(code)}</code>`;
    else if (bold !== undefined) html += `<strong>${renderInline(bold)}</strong>`;
    else if (linkText !== undefined) html += link(linkUrl, renderInline(linkText));
    else html += link(bareUrl, escapeHtml(bareUrl));
    position = match.index + whole.length;
  }
  return html + escapeHtml(text.slice(position));
}

function renderListItem(text) {
  const pullRequest = text.match(PULL_REQUEST_BULLET);
  if (pullRequest === null) return `<li>${renderInline(text)}</li>`;
  const [, title, url, number] = pullRequest;
  return `<li>${renderInline(title)} ${link(url, `#${number}`)}</li>`;
}

function splitBlocks(markdown) {
  const blocks = [];
  let current = null;
  for (const line of markdown.split(/\r?\n/)) {
    if (line.trim() === '') {
      current = null;
    } else if (HEADING.test(line)) {
      blocks.push({ kind: 'heading', lines: [line.replace(HEADING, '')] });
      current = null;
    } else if (LIST_ITEM.test(line)) {
      if (current?.kind !== 'list') blocks.push((current = { kind: 'list', lines: [] }));
      current.lines.push(line.replace(LIST_ITEM, ''));
    } else if (current?.kind === 'list' && /^\s/.test(line)) {
      current.lines[current.lines.length - 1] += ` ${line.trim()}`;
    } else {
      if (current?.kind !== 'paragraph') blocks.push((current = { kind: 'paragraph', lines: [] }));
      current.lines.push(line.trim());
    }
  }
  return blocks;
}

function renderBlock(block) {
  if (block.kind === 'heading') return `<h4>${renderInline(block.lines[0])}</h4>`;
  if (block.kind === 'list') return `<ul>${block.lines.map(renderListItem).join('')}</ul>`;
  return `<p>${renderInline(block.lines.join(' '))}</p>`;
}

export function renderBlocks(markdown) {
  return splitBlocks(markdown).map(renderBlock).join('\n');
}
