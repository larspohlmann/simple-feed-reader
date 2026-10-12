import { makeSubscription } from '../testing/subscription.factory';
import { displayTitle, isUnrenamedHome } from './display-title';

const home = { network: 'bluesky', accountId: 3, kind: 'home' } as const;

describe('displayTitle', () => {
  it('names an unrenamed Home with the given label', () => {
    const subscription = makeSubscription({ title: 'Timeline', social: home });

    expect(isUnrenamedHome(subscription)).toBe(true);
    expect(displayTitle(subscription, 'Startseite')).toBe('Startseite');
  });

  it('keeps a renamed Home, a list and an ordinary feed under their own title', () => {
    const renamed = makeSubscription({ title: 'Main', customTitle: 'Main', social: home });
    const list = makeSubscription({ title: 'Writers', social: { ...home, kind: 'list' } });
    const ordinary = makeSubscription({ title: 'Heise' });

    expect(displayTitle(renamed, 'Startseite')).toBe('Main');
    expect(displayTitle(list, 'Startseite')).toBe('Writers');
    expect(displayTitle(ordinary, 'Startseite')).toBe('Heise');
  });
});
