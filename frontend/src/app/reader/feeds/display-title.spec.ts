import { makeSubscription } from '../testing/subscription.factory';
import { isUnrenamedHome, titleFollowsSource } from './display-title';

const home = { network: 'bluesky', accountId: 3, kind: 'home' } as const;

describe('isUnrenamedHome', () => {
  it('holds for a Home without a title of its own', () => {
    expect(isUnrenamedHome(makeSubscription({ title: 'Timeline', social: home }))).toBe(true);
  });

  it('fails for a renamed Home, a list and an ordinary feed', () => {
    const renamed = makeSubscription({ title: 'Main', customTitle: 'Main', social: home });
    const list = makeSubscription({ title: 'Writers', social: { ...home, kind: 'list' } });
    const ordinary = makeSubscription({ title: 'Heise' });

    expect([renamed, list, ordinary].map(isUnrenamedHome)).toEqual([false, false, false]);
  });
});

describe('titleFollowsSource', () => {
  const search = { network: 'bluesky', accountId: 3, kind: 'search', query: 'rss' } as const;

  it('holds for an unrenamed Home and an unrenamed search', () => {
    const subscriptions = [
      makeSubscription({ social: home }),
      makeSubscription({ social: search }),
    ];

    expect(subscriptions.map(titleFollowsSource)).toEqual([true, true]);
  });

  it('fails for a renamed search and an unrenamed list', () => {
    const renamed = makeSubscription({ customTitle: 'Readers', social: search });
    const list = makeSubscription({ social: { ...home, kind: 'list' } });

    expect([renamed, list].map(titleFollowsSource)).toEqual([false, false]);
  });
});
