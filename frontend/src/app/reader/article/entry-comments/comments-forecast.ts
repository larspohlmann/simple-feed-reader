import { EntryDto } from '../../models';
import { SocialCountsService } from '../social-counts/social-counts.service';
import { CommentsService } from './comments.service';

type ForecastEntry = Pick<EntryDto, 'id' | 'comments' | 'social'>;

/** Rendered comments per direct reply a post counts, measured over 100 posts (#1662). */
const COMMENTS_PER_REPLY = 1.7;
/** The backend's `social_replies_max_count`. */
const MOST_THREAD_COMMENTS = 100;
/** The comment body's `--lh-normal`. */
const COMMENT_LINE_HEIGHT = 1.5;
/** A comment's byline, padding and media, in lines. */
const COMMENT_CHROME_LINES = 3;
/** A comment's text, in lines on a pane one em wide. */
const COMMENT_TEXT_LINES_PER_EM = 70;

export function expectedThreadSize(replies: number): number {
  return Math.min(MOST_THREAD_COMMENTS, Math.round(replies * COMMENTS_PER_REPLY));
}

export function expectedCommentHeight(readingFontSize: number, width: number): number {
  const textLines = (COMMENT_TEXT_LINES_PER_EM * readingFontSize) / width;
  return readingFontSize * COMMENT_LINE_HEIGHT * (COMMENT_CHROME_LINES + textLines);
}

export class CommentsForecast {
  constructor(
    private readonly entry: () => ForecastEntry | null,
    private readonly comments: CommentsService,
    private readonly counts: SocialCountsService,
  ) {}

  /** The section's height once its comments render, or null when it already shows what it will. */
  readonly expectedHeight = (section: HTMLElement, content: HTMLElement): number | null => {
    const entry = this.entry();
    if (!entry?.social || entry.comments !== 'auto' || !this.awaitsComments(entry.id)) return null;
    const replies = this.replies(entry.id);
    if (replies === null || section.clientWidth === 0) return null;
    const readingFontSize = parseFloat(getComputedStyle(content).fontSize);
    if (!Number.isFinite(readingFontSize) || readingFontSize <= 0) return null;
    return (
      expectedThreadSize(replies) * expectedCommentHeight(readingFontSize, section.clientWidth)
    );
  };

  private awaitsComments(id: number): boolean {
    const status = this.comments.state(id)().status;
    return status === 'idle' || status === 'loading';
  }

  private replies(id: number): number | null {
    const state = this.counts.state(id)();
    return state.status === 'ok' ? state.counts.replies : null;
  }
}
