const segmenter = new Intl.Segmenter('en', { granularity: 'grapheme' });

export function graphemeLength(text) {
  return [...segmenter.segment(text)].length;
}

export function extractHighlights(releaseBody) {
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
    .map((paragraph) => toPlainText(paragraph.split('\n').map((line) => line.trim()).join(' ')))
    .filter((paragraph) => paragraph !== '');
}

function toPlainText(markdown) {
  return markdown
    .replace(/\*\*(.+?)\*\*/g, '$1')
    .replace(/\[([^\]]+)\]\((https?:[^)\s]+)\)/g, '$1 ($2)')
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
  const graphemes = [...segmenter.segment(word)].map((segment) => segment.segment);
  const chunks = [];
  for (let index = 0; index < graphemes.length; index += limit) {
    chunks.push(graphemes.slice(index, index + limit).join(''));
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

export const HOMEPAGE_URL = 'https://lars-pohlmann.de/simplefeedreader/';
export const HOMEPAGE_TITLE = 'simple-feed-reader';
export const HOMEPAGE_DESCRIPTION =
  'A self-hosted RSS/Atom reader for one or several users. Magazine, list and two-pane layouts, podcasts, '
  + 'YouTube, Bluesky and Mastodon, optional AI recommendations. Free and open source.';

export function buildThread({ tag, releaseUrl, highlights, limit }) {
  return [
    `simple-feed-reader ${tag} is out. The highlights follow in this thread. #rss #rssreader\n\n${HOMEPAGE_URL}`,
    ...highlights.flatMap((highlight) => splitToFit(highlight, limit)),
    `Full release notes: ${releaseUrl}`,
  ];
}
