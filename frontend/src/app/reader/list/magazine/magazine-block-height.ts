import { EntryDto } from '../../models';
import { EntryMediumDto, mediumRatio } from '../../entry-medium';
import { entryDek, entryHeadline } from '../../entry/entry-text';
import { QuotedPostDto } from '../../quoted-post/quoted-post';
import { BLOCK_HEIGHT, EntryKind } from './magazine-block';
import { sharedMedia, sharesQuotedPost, threadRootMedia } from './magazine-slot-fit';
import { LinkCardDto } from '../../link-card/link-card';

/** Measured at 390px viewport width, like BLOCK_HEIGHT; a quote share's parts, in the same units. */
const QUOTING = {
  chrome: 82,
  ownLine: 22.5,
  ownCharsPerLine: 46,
  ownLinesMax: 6,
  quoteChrome: 54,
  quoteLine: 22.5,
  quoteCharsPerLine: 40,
  quoteLinesMax: 8,
  nestedLine: 24.5,
  ownMediaWidth: 340,
  quoteMediaWidth: 314,
  mediaMaxHeight: 480,
  gap: 8,
} as const;

/** A thread's parts, in the same units (#1668). */
const THREAD = {
  chrome: 82,
  line: 22.5,
  charsPerLine: 44,
  // In step with `$outlier-lines` in `_post-text.scss`, the clamp a post's text renders under.
  linesMax: 30,
  dekLine: 18.2,
  dekCharsPerLine: 50,
  dekLinesMax: 3,
  mediaWidth: 320,
  cardWithImage: 330,
  cardBare: 110,
  gap: 12,
} as const;

const GRID_RATIO = 16 / 9;

export function blockHeight(kind: EntryKind, entry: EntryDto): number {
  if (kind === 'quoting') return quotingHeight(entry);
  if (kind === 'thread') return threadHeight(entry);
  return BLOCK_HEIGHT[kind];
}

/** Chrome, then each post on the thread line: its text, media, quote, card and the gap below it. */
export function threadHeight(entry: EntryDto): number {
  return entry.thread.reduce(
    (sum, reply) => sum + threadPostHeight(reply.text, reply.media, reply.linkCard),
    THREAD.chrome + threadRootHeight(entry),
  );
}

function threadRootHeight(entry: EntryDto): number {
  const quote = sharesQuotedPost(entry) ? entry.quote : null;
  const pendingCard = entry.linkCards.length === 0 && entry.pending ? THREAD.cardBare : 0;
  return (
    threadPostHeight(entryHeadline(entry), threadRootMedia(entry), entry.linkCards[0] ?? null) +
    THREAD.dekLine * lineCount(entryDek(entry), THREAD.dekCharsPerLine, THREAD.dekLinesMax) +
    (quote ? quotedBoxHeight(quote) + QUOTING.gap : 0) +
    pendingCard
  );
}

function threadPostHeight(text: string, media: EntryMediumDto[], card: LinkCardDto | null): number {
  const lines = THREAD.line * lineCount(text, THREAD.charsPerLine, THREAD.linesMax);
  return lines + withGap(mediaHeight(media, THREAD.mediaWidth)) + linkCardHeight(card) + THREAD.gap;
}

function linkCardHeight(card: LinkCardDto | null): number {
  if (!card) return 0;
  return card.imageUrl ? THREAD.cardWithImage : THREAD.cardBare;
}

/** Chrome, the post's own text and media, then the quoted box: its header, text and media (#1612). */
export function quotingHeight(entry: EntryDto): number {
  const own =
    QUOTING.ownLine *
      lineCount(entryHeadline(entry), QUOTING.ownCharsPerLine, QUOTING.ownLinesMax) +
    withGap(mediaHeight(sharedMedia(entry), QUOTING.ownMediaWidth));
  const quote = entry.quote;
  if (!quote) return QUOTING.chrome + own;
  return QUOTING.chrome + own + QUOTING.gap + quotedBoxHeight(quote);
}

/** The quoted box: its header, text, media, link card and nested-quote line. */
function quotedBoxHeight(quote: QuotedPostDto): number {
  return (
    QUOTING.quoteChrome +
    QUOTING.quoteLine * lineCount(quote.text, QUOTING.quoteCharsPerLine, QUOTING.quoteLinesMax) +
    withGap(mediaHeight(quote.media, QUOTING.quoteMediaWidth)) +
    withGap(linkCardHeight(quote.linkCard)) +
    (quote.nestedQuote ? QUOTING.gap + QUOTING.nestedLine : 0)
  );
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
  return Math.min(width / mediumRatio(only), QUOTING.mediaMaxHeight);
}

function withGap(height: number): number {
  return height === 0 ? 0 : height + QUOTING.gap;
}
