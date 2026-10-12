import { makeSubscription } from '../testing/subscription.factory';
import { isUnrenamedHome } from './display-title';

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
