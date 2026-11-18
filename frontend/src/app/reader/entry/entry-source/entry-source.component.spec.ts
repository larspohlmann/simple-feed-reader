import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { anEntry } from '../../../../testing/entry-testing';
import { EntryDto } from '../../models';
import { SubscriptionRows } from '../../state/subscription-rows';
import { makeSubscription } from '../../testing/subscription.factory';
import {
  aMastodonAccount,
  provideSocialAccounts,
} from '../../../../testing/social-accounts-testing';
import { OwnInstanceLinks } from '../../own-instance/own-instance-links.service';
import { EntrySourceComponent } from './entry-source.component';

const BOB = { name: 'Bob', url: 'https://bsky.app/profile/bob', imageUrl: null };

function renderSource(
  entry: EntryDto,
  sourceFormat: 'xml' | 'saved',
  layout: 'row' | 'kicker' | 'article' = 'row',
): HTMLElement {
  TestBed.configureTestingModule({
    imports: [EntrySourceComponent, provideTranslocoTesting()],
    providers: [provideRouter([]), provideSocialAccounts([aMastodonAccount()])],
  });
  TestBed.inject(SubscriptionRows).stored.set([
    makeSubscription({
      id: entry.subscriptionId,
      title: sourceFormat === 'saved' ? 'Saved from Bluesky' : 'A feed',
      sourceFormat,
      social:
        sourceFormat === 'saved' ? { network: 'bluesky', accountId: null, kind: 'saved' } : null,
    }),
  ]);
  const fixture = TestBed.createComponent(EntrySourceComponent);
  fixture.componentRef.setInput('entry', entry);
  fixture.componentRef.setInput('layout', layout);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

function sourceLine(entry: EntryDto, sourceFormat: 'xml' | 'saved'): string {
  const line = renderSource(entry, sourceFormat).cloneNode(true) as HTMLElement;
  line.querySelector('app-favicon')?.remove();
  return (line.textContent ?? '').replace(/\s+/g, ' ').trim();
}

describe('EntrySourceComponent', () => {
  it('names the poster, then the saved feed a saved post now lives in', () => {
    expect(sourceLine(anEntry({ poster: BOB }), 'saved')).toBe('Bob · Saved from Bluesky');
  });

  it('names the poster alone in an ordinary feed', () => {
    expect(sourceLine(anEntry({ poster: BOB }), 'xml')).toBe('Bob');
  });

  it('names the feed alone when nobody else posted', () => {
    expect(sourceLine(anEntry(), 'xml')).toBe('A feed');
  });

  it('links a Mastodon poster through the reader’s own instance', () => {
    const poster = { name: 'Gargron', url: 'https://mastodon.social/@Gargron', imageUrl: null };
    const social = { network: 'mastodon' as const, isLiked: false, canLike: true, isOwn: false };

    const link = renderSource(anEntry({ poster, social }), 'saved').querySelector('a.source');

    expect(link?.getAttribute('href')).toBe(
      `https://home.example/authorize_interaction?uri=${encodeURIComponent(poster.url)}`,
    );
  });

  it('links a Bluesky poster to its own profile', () => {
    const social = { network: 'bluesky' as const, isLiked: false, canLike: true, isOwn: false };

    const link = renderSource(anEntry({ poster: BOB, social }), 'saved').querySelector('a.source');

    expect(link?.getAttribute('href')).toBe(BOB.url);
  });

  it("prefixes its layout class, so the list row's own .row card styles never reach it", () => {
    expect([...renderSource(anEntry(), 'xml').classList]).toEqual(['source-row']);
  });

  describe('Lemmy community', () => {
    const community = { name: 'technology', url: 'https://lemmy.world/c/technology' };
    const alice = { name: 'alice', url: 'https://lemmy.world/u/alice', imageUrl: null };

    it('names the community after the poster in a row, unlinked', () => {
      const host = renderSource(anEntry({ poster: alice, community }), 'xml', 'row');

      expect((host.textContent ?? '').replace(/\s+/g, ' ')).toContain('alice · c/technology');
      expect(host.querySelector('a.community')).toBeNull();
    });

    it('names the community in a kicker as plain text', () => {
      const host = renderSource(anEntry({ poster: alice, community }), 'xml', 'kicker');

      expect(host.querySelector('span.community')?.textContent?.trim()).toBe('· c/technology');
    });

    it('links the community in the article through OwnInstanceLinks', () => {
      const host = renderSource(anEntry({ poster: alice, community }), 'xml', 'article');
      const links = TestBed.inject(OwnInstanceLinks);

      expect(host.querySelector('a.community')?.getAttribute('href')).toBe(
        links.open(community.url, 'lemmy'),
      );
    });

    it('names no community when the entry has none', () => {
      const host = renderSource(anEntry({ poster: alice }), 'xml', 'article');

      expect(host.textContent).not.toContain('· c/');
    });
  });
});
