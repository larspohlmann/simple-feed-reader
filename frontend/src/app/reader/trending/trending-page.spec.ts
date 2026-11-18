import { aStory } from '../../../testing/entry-testing';
import { trendingPage } from './trending-page';

describe('trendingPage', () => {
  it('lists the stories as items, in the order the server ranked them, with no further page', () => {
    const stories = [aStory({ id: 4 }), aStory({ id: 2 })];

    expect(trendingPage({ window: 'day', builtAt: null, stories })).toEqual({
      items: stories,
      nextCursor: null,
    });
  });
});
