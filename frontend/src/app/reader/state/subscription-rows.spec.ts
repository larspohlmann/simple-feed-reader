import { computed } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { makeSubscription } from '../testing/subscription.factory';
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
const list = makeSubscription({
  id: 6,
  title: 'Writers',
  social: { network: 'bluesky', accountId: 1, kind: 'list' },
});
const savedMastodon = makeSubscription({
  id: 7,
  title: 'Saved from mastodon',
  sourceFormat: 'saved',
  social: { network: 'mastodon', accountId: null, kind: 'saved' },
});
const savedRenamed = makeSubscription({
  id: 8,
  title: 'My keepers',
  customTitle: 'My keepers',
  sourceFormat: 'saved',
});
const ordinary = makeSubscription({ id: 5, title: 'Heise' });

function rows(): SubscriptionRows {
  TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] });
  const held = TestBed.inject(SubscriptionRows);
  held.stored.set([home, renamed, list, ordinary]);
  return held;
}

describe('SubscriptionRows', () => {
  it('titles an unrenamed Home by its network and keeps every other title', () => {
    const held = rows();

    expect(held.all().map((subscription) => subscription.title)).toEqual([
      'Home@bsky',
      'Main',
      'Writers',
      'Heise',
    ]);
    expect(held.find(3)?.title).toBe('Home@bsky');
    expect(held.find(9)).toBeNull();
  });

  it('keeps the stored rows as the server sent them', () => {
    const held = rows();

    expect(held.stored()[0].title).toBe('Home');
    expect(held.find(3)?.customTitle).toBeNull();
  });

  it('retitles a Home held in a computed when the language switches', () => {
    const held = rows();
    const title = computed(() => held.find(3)?.title);
    expect(title()).toBe('Home@bsky');
    const i18n = TestBed.inject(TranslocoService);
    i18n.setTranslation({ reader: { socialHome: 'Start@{{network}}' } }, 'de', { merge: true });

    i18n.setActiveLang('de');

    expect(title()).toMatch(/^Start@/);
  });

  it('titles an unrenamed saved feed from the language and keeps a custom title', () => {
    const held = rows();
    held.stored.set([savedMastodon, savedRenamed]);

    expect(held.find(7)?.title).toBe('Saved from Mastodon');
    expect(held.find(8)?.title).toBe('My keepers');
  });
});
