import { signal } from '@angular/core';
import { anEntry } from '../../../../testing/entry-testing';
import { EntryDto } from '../../models';
import { SocialCountsService, SocialCountsState } from '../social-counts/social-counts.service';
import { CommentsService, CommentsState } from './comments.service';
import { CommentsForecast, expectedCommentHeight, expectedThreadSize } from './comments-forecast';

const READING_FONT = 16;
const PHONE_WIDTH = 343;

function sectionOfWidth(width: number): HTMLElement {
  const section = document.createElement('section');
  Object.defineProperty(section, 'clientWidth', { value: width });
  return section;
}

const countsOf = (replies: number): SocialCountsState => ({
  status: 'ok',
  counts: { likes: 0, reposts: 0, replies, quotes: 0 },
  likedAtLoad: false,
  loadedAt: 0,
});

function forecastFor(
  entry: EntryDto,
  comments: CommentsState = { status: 'idle' },
  counts: SocialCountsState = countsOf(10),
): CommentsForecast {
  return new CommentsForecast(
    () => entry,
    { state: () => signal(comments) } as unknown as CommentsService,
    { state: () => signal(counts) } as unknown as SocialCountsService,
  );
}

const socialPost = (over: Partial<EntryDto> = {}): EntryDto =>
  anEntry({
    id: 1,
    comments: 'auto',
    social: { network: 'bluesky', isLiked: false, canLike: true },
    ...over,
  });

describe('expectedThreadSize', () => {
  it('counts the nested replies a direct reply draws', () => {
    expect(expectedThreadSize(10)).toBe(17);
  });

  it('stops at the most comments a thread renders', () => {
    expect(expectedThreadSize(71)).toBe(100);
  });
});

describe('expectedCommentHeight', () => {
  it('runs taller on a narrow pane, where the text wraps more', () => {
    expect(expectedCommentHeight(READING_FONT, PHONE_WIDTH)).toBeCloseTo(150.4, 1);
    expect(expectedCommentHeight(READING_FONT, 688)).toBeCloseTo(111.1, 1);
  });
});

describe('CommentsForecast', () => {
  it('expects the thread a social post counts while its comments are on the way', () => {
    const expected = expectedThreadSize(10) * expectedCommentHeight(READING_FONT, PHONE_WIDTH);

    for (const status of ['idle', 'loading'] as const) {
      const forecast = forecastFor(socialPost(), { status });
      expect(forecast.expectedHeight(sectionOfWidth(PHONE_WIDTH), READING_FONT)).toBeCloseTo(
        expected,
      );
    }
  });

  it('leaves the comments to their own height once they arrive', () => {
    const loaded: CommentsState = {
      status: 'ok',
      comments: [],
      omitted: 0,
      fetchedAt: '2026-10-17T12:00:00Z',
      loadedAt: 0,
    };

    expect(
      forecastFor(socialPost(), loaded).expectedHeight(sectionOfWidth(PHONE_WIDTH), READING_FONT),
    ).toBeNull();
  });

  it('expects nothing before the post counts its replies', () => {
    const forecast = forecastFor(socialPost(), { status: 'idle' }, { status: 'loading' });

    expect(forecast.expectedHeight(sectionOfWidth(PHONE_WIDTH), READING_FONT)).toBeNull();
  });

  it('expects nothing of comments the reader loads by hand', () => {
    const forecast = forecastFor(socialPost({ comments: 'manual' }));

    expect(forecast.expectedHeight(sectionOfWidth(PHONE_WIDTH), READING_FONT)).toBeNull();
  });

  it('expects nothing of a discussion without a reply count', () => {
    const forecast = forecastFor(socialPost({ social: null }));

    expect(forecast.expectedHeight(sectionOfWidth(PHONE_WIDTH), READING_FONT)).toBeNull();
  });

  it('expects nothing it cannot lay out', () => {
    const forecast = forecastFor(socialPost());

    expect(forecast.expectedHeight(sectionOfWidth(0), READING_FONT)).toBeNull();
    expect(forecast.expectedHeight(sectionOfWidth(PHONE_WIDTH), Number.NaN)).toBeNull();
    expect(forecast.expectedHeight(sectionOfWidth(PHONE_WIDTH), 0)).toBeNull();
  });
});
