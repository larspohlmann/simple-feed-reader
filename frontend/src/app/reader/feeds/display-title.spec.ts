import { makeSubscription } from '../testing/subscription.factory';
import { displayTitle, isUnrenamedHome } from './display-title';

const home = { network: 'bluesky', accountId: 3, kind: 'home' } as const;

const homeLabel = (network: string): string =>
  `Startseite@${network === 'bluesky' ? 'bsky' : network}`;

describe('displayTitle', () => {
  it('names an unrenamed Home with the given label', () => {
    const subscription = makeSubscription({ title: 'Timeline', social: home });

    expect(isUnrenamedHome(subscription)).toBe(true);
    expect(displayTitle(subscription, homeLabel)).toBe('Startseite@bsky');
  });

  it('keeps a renamed Home, a list and an ordinary feed under their own title', () => {
    const renamed = makeSubscription({ title: 'Main', customTitle: 'Main', social: home });
    const list = makeSubscription({ title: 'Writers', social: { ...home, kind: 'list' } });
    const ordinary = makeSubscription({ title: 'Heise' });

    expect(displayTitle(renamed, homeLabel)).toBe('Main');
    expect(displayTitle(list, homeLabel)).toBe('Writers');
    expect(displayTitle(ordinary, homeLabel)).toBe('Heise');
  });
});
