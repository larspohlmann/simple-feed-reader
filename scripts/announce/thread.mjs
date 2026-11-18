export const HOMEPAGE_URL = 'https://lars-pohlmann.de/simplefeedreader/';
export const HOMEPAGE_TITLE = 'simple feed reader — an RSS reader that lays feeds out like a magazine';
export const HOMEPAGE_DESCRIPTION =
  'A self-hosted RSS/Atom reader for one or several users. Magazine, list and two-pane layouts, podcasts, '
  + 'YouTube, Bluesky and Mastodon, optional AI recommendations. Free and open source.';

const segmenter = new Intl.Segmenter('en', { granularity: 'grapheme' });

function graphemes(text) {
  return [...segmenter.segment(text)].map((segment) => segment.segment);
}

export function graphemeLength(text) {
  return graphemes(text).length;
}

export const LEAD_LIMIT = 150;
const TAGS = '#RSS #SelfHosted #OpenSource';

export function extractHighlights(releaseBody) {
  const highlights = highlightParagraphs(releaseBody).map(toHighlight);
  const [first, ...rest] = highlights;
  if (first !== undefined && first.title === null) {
    return { lead: first.text, highlights: rest };
  }
  return { lead: null, highlights };
}

function highlightParagraphs(releaseBody) {
  const lines = releaseBody.replace(/\r\n/g, '\n').split('\n');
  const start = lines.findIndex((line) => /^###\s+Highlights\s*$/.test(line));
  if (start === -1) {
    return [];
  }
  const rest = lines.slice(start + 1);
  const end = rest.findIndex((line) => /^#{1,3}\s/.test(line));
  const block = (end === -1 ? rest : rest.slice(0, end)).flatMap((line) =>
    /^\s*[-*]\s+/.test(line) ? ['', line.replace(/^\s*[-*]\s+/, '')] : [line],
  );
  return block
    .join('\n')
    .split(/\n\s*\n/)
    .map((paragraph) => paragraph.split('\n').map((line) => line.trim()).join(' ').trim())
    .filter((paragraph) => paragraph !== '');
}

function toHighlight(paragraph) {
  return { title: titleOf(paragraph), text: toPlainText(paragraph) };
}

function titleOf(paragraph) {
  const bold = paragraph.match(/^\*\*(.+?)\*\*/);
  return bold === null ? null : toPlainText(bold[1]).replace(/\.$/, '');
}

function toPlainText(markdown) {
  return markdown
    .replace(/\*\*(.+?)\*\*/g, '$1')
    .replace(/\[([^\]]+)\]\((https?:[^)\s]+)\)/g, '$1: $2')
    .replace(/`([^`]+)`/g, '$1')
    .trim();
}

export function splitToFit(text, limit) {
  if (graphemeLength(text) <= limit) {
    return [text];
  }
  const pieces = text
    .split(/(?<=[.!?])\s+/)
    .flatMap((sentence) => (graphemeLength(sentence) <= limit ? [sentence] : wordsWithin(sentence, limit)));
  return pack(pieces, limit);
}

function wordsWithin(sentence, limit) {
  return sentence
    .split(/\s+/)
    .flatMap((word) => (graphemeLength(word) <= limit ? [word] : graphemeChunks(word, limit)));
}

function graphemeChunks(word, limit) {
  const characters = graphemes(word);
  const chunks = [];
  for (let index = 0; index < characters.length; index += limit) {
    chunks.push(characters.slice(index, index + limit).join(''));
  }
  return chunks;
}

function pack(pieces, limit) {
  const posts = [];
  let current = '';
  for (const piece of pieces) {
    const candidate = current === '' ? piece : `${current} ${piece}`;
    if (graphemeLength(candidate) <= limit) {
      current = candidate;
      continue;
    }
    posts.push(current);
    current = piece;
  }
  if (current !== '') {
    posts.push(current);
  }
  return posts;
}

export function buildThread({ tag, releaseUrl, lead, highlights, limit }) {
  return [
    rootPost({ tag, lead, highlights, limit }),
    ...highlights.flatMap((highlight) => splitToFit(highlight.text, limit)),
    `Full release notes: ${releaseUrl}`,
  ];
}

function rootPost({ tag, lead, highlights, limit }) {
  const product = `simple-feed-reader ${tag}, a self-hosted, open-source RSS reader`;
  const fits = (post) => graphemeLength(post) <= limit;
  const leadPost = lead === null ? null : withHomepage(`${product}, is out. ${lead}`);
  if (leadPost !== null && fits(leadPost)) {
    return leadPost;
  }
  const titles = highlights.map((highlight) => highlight.title).filter((title) => title !== null);
  for (let shown = titles.length; shown > 0; shown -= 1) {
    const post = titleList(product, titles, shown);
    if (fits(post)) {
      return post;
    }
  }
  return withHomepage(`${product}, is out.`);
}

function titleList(product, titles, shown) {
  const lines = titles.slice(0, shown).map((title) => `• ${title}`);
  if (shown < titles.length) {
    lines.push(`… and ${titles.length - shown} more below`);
  }
  return withHomepage(`New in ${product}:\n\n${lines.join('\n')}`);
}

function withHomepage(text) {
  return `${text}\n\n${HOMEPAGE_URL}\n\n${TAGS}`;
}
