import { computed } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { anEntry } from '../../../testing/entry-testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { makeSubscription } from '../testing/subscription.factory';
import { FeedNames } from './feed-names';
import { SubscriptionRows } from './subscription-rows';

const home = makeSubscription({
  id: 3,
  title: 'Home',
  social: { network: 'bluesky', accountId: 1, kind: 'home' },
});
const renamed = makeSubscription({
  id: 4,
  title: 'Main',
  customTitle: 'Main',
  social: { network: 'mastodon', accountId: 2, kind: 'home' },
});
const ordinary = makeSubscription({ id: 5, title: 'Heise' });

function names(): FeedNames {
  TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] });
  TestBed.inject(SubscriptionRows).all.set([home, renamed, ordinary]);
  return TestBed.inject(FeedNames);
}

describe('FeedNames', () => {
  it('names an unrenamed Home by its network and every other feed by its title', () => {
    const feedNames = names();

    expect([3, 4, 5, 9].map((id) => feedNames.nameOf(id))).toEqual([
      'Home@bsky',
      'Main',
      'Heise',
      null,
    ]);
  });

  it("names a post's feed where the backend fell back to the feed's title, and keeps a poster", () => {
    const feedNames = names();
    const fallback = anEntry({ subscriptionId: 3, subscriptionTitle: 'Home', source: 'Home' });
    const posted = anEntry({ subscriptionId: 3, subscriptionTitle: 'Home', source: 'Alice' });

    expect(feedNames.sourceOf(fallback)).toBe('Home@bsky');
    expect(feedNames.sourceOf(posted)).toBe('Alice');
    expect(feedNames.feedOf(posted)).toBe('Home@bsky');
  });

  it('keeps the backend title of a feed it does not know', () => {
    const feedNames = names();
    const unknown = anEntry({ subscriptionId: 9, subscriptionTitle: 'Gone', source: 'Gone' });

    expect(feedNames.sourceOf(unknown)).toBe('Gone');
    expect(feedNames.feedOf(unknown)).toBe('Gone');
  });

  it('renames a Home held in a computed when the language switches', () => {
    const feedNames = names();
    const name = computed(() => feedNames.nameOf(3));
    expect(name()).toBe('Home@bsky');
    const i18n = TestBed.inject(TranslocoService);
    i18n.setTranslation({ reader: { socialHome: 'Start@{{network}}' } }, 'de', { merge: true });

    i18n.setActiveLang('de');

    expect(name()).toMatch(/^Start@/);
  });
});
