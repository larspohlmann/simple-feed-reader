import { EntryDto } from '../../models';
import { EntryMediumDto } from '../../entry-medium';
import { entryHeadline } from '../../entry/entry-text';
import { BLOCK_HEIGHT, EntryKind } from './magazine-block';
import { sharedMedia } from './magazine-slot-fit';

/** Measured at 390px viewport width, like BLOCK_HEIGHT; a quote share's parts, in the same units. */
const QUOTING = {
  chrome: 150,
  ownLine: 24,
  ownCharsPerLine: 44,
  ownLinesMax: 6,
  quoteChrome: 56,
  quoteLine: 24,
  quoteCharsPerLine: 40,
  quoteLinesMax: 8,
  ownMediaWidth: 342,
  quoteMediaWidth: 318,
  mediaMaxHeight: 480,
  gap: 8,
} as const;

const GRID_RATIO = 16 / 9;

export function blockHeight(kind: EntryKind, entry: EntryDto): number {
  return kind === 'quoting' ? quotingHeight(entry) : BLOCK_HEIGHT[kind];
}

/** Chrome, the post's own text and media, then the quoted box: its header, text and media (#1612). */
export function quotingHeight(entry: EntryDto): number {
  const own =
    QUOTING.ownLine *
      lineCount(entryHeadline(entry), QUOTING.ownCharsPerLine, QUOTING.ownLinesMax) +
    withGap(mediaHeight(sharedMedia(entry), QUOTING.ownMediaWidth));
  const quote = entry.quote;
  if (!quote) return QUOTING.chrome + own;
  const quoted =
    QUOTING.quoteChrome +
    QUOTING.quoteLine * lineCount(quote.text, QUOTING.quoteCharsPerLine, QUOTING.quoteLinesMax) +
    withGap(mediaHeight(quote.media, QUOTING.quoteMediaWidth));
  return QUOTING.chrome + own + QUOTING.gap + quoted;
}

/** Lines the text wraps to at the measure, each line break starting a new one, at most `max`. */
function lineCount(text: string, charsPerLine: number, max: number): number {
  if (text === '') return 0;
  const lines = text
    .split('\n')
    .reduce((sum, line) => sum + Math.max(1, Math.ceil(line.length / charsPerLine)), 0);
  return Math.min(lines, max);
}

/** One item at its own shape under the height cap; a grid of 2–4 as one 16:9 block. */
function mediaHeight(media: EntryMediumDto[], width: number): number {
  if (media.length === 0) return 0;
  if (media.length > 1) return width / GRID_RATIO;
  const [only] = media;
  const ratio = only.width && only.height ? only.width / only.height : GRID_RATIO;
  return Math.min(width / ratio, QUOTING.mediaMaxHeight);
}

function withGap(height: number): number {
  return height === 0 ? 0 : height + QUOTING.gap;
}
