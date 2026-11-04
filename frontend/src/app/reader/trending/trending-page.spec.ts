import { anEntry } from '../../../testing/entry-testing';
import { TrendingResponseDto } from '../models';
import { trendingPage } from './trending-page';

const response = (): TrendingResponseDto => ({
  window: 'day',
  builtAt: '2026-11-04T08:00:00Z',
  stories: [
    {
      lead: anEntry({ id: 1, title: 'Lead one' }),
      others: [anEntry({ id: 2 }), anEntry({ id: 3 })],
      outletCount: 7,
      viewed: false,
    },
    { lead: anEntry({ id: 4, title: 'Lead two' }), others: [], outletCount: 3, viewed: true },
  ],
});

describe('trendingPage', () => {
  it('lists each story as its lead, in the order the server ranked them', () => {
    expect(trendingPage(response()).entries.map((entry) => entry.id)).toEqual([1, 4]);
  });

  it('carries the story on its lead: outlet count, other outlets and the viewed verdict', () => {
    const [first, second] = trendingPage(response()).entries;

    expect(first.coverage).toEqual({
      outletCount: 7,
      others: [anEntry({ id: 2 }), anEntry({ id: 3 })],
      viewed: false,
    });
    expect(second.coverage?.viewed).toBe(true);
  });

  it('keeps the lead row itself as the server sent it', () => {
    expect(trendingPage(response()).entries[0].title).toBe('Lead one');
  });

  it('has no further page', () => {
    expect(trendingPage(response()).nextCursor).toBeNull();
  });
});
