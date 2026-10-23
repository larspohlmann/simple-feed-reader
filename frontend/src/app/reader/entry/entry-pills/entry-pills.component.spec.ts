import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { EntryPillsComponent } from './entry-pills.component';
import { SubscriptionRows } from '../../state/subscription-rows';
import { makeSubscription } from '../../testing/subscription.factory';
import { SavedSearchMembershipDto, SubscriptionTagDto } from '../../models';

const tag = (id: number, name: string): SubscriptionTagDto => ({
  id,
  name,
  color: null,
  icon: null,
  position: 0,
  parentId: null,
});

const savedSearch = (id: number, term: string): SavedSearchMembershipDto => ({
  id,
  slug: `${id}-${term}`,
  term,
});

function mount(
  tags: SubscriptionTagDto[],
  savedSearches: SavedSearchMembershipDto[] = [],
  short = false,
) {
  TestBed.configureTestingModule({
    imports: [EntryPillsComponent, provideTranslocoTesting()],
    providers: [provideRouter([{ path: '**', children: [] }])],
  });
  const fixture = TestBed.createComponent(EntryPillsComponent);
  fixture.componentRef.setInput('tags', tags);
  fixture.componentRef.setInput('savedSearches', savedSearches);
  fixture.componentRef.setInput('short', short);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

const pillNames = (element: HTMLElement) =>
  [...element.querySelectorAll('.pill .name')].map((name) => name.textContent);

describe('EntryPillsComponent', () => {
  it('lists the tag pills first, then the saved-search pills', () => {
    const element = mount([tag(1, 'Tech'), tag(2, 'News')], [savedSearch(5, 'climate')]);
    expect(pillNames(element)).toEqual(['Tech', 'News', 'climate']);
  });

  it('leads with a Short pill, as plain text rather than a link, when asked to', () => {
    const element = mount([tag(1, 'Tech')], [], true);
    const first = element.querySelector('.pill')!;

    expect(first.classList).toContain('short');
    expect(first.tagName).toBe('SPAN');
    expect(first.textContent?.trim()).toBe('Short');
  });

  it('shows no Short pill by default', () => {
    expect(mount([tag(1, 'Tech')]).querySelector('.pill.short')).toBeNull();
  });

  it('renders a lone Short pill without being given tags', () => {
    TestBed.configureTestingModule({
      imports: [EntryPillsComponent, provideTranslocoTesting()],
      providers: [provideRouter([])],
    });
    const fixture = TestBed.createComponent(EntryPillsComponent);
    fixture.componentRef.setInput('short', true);
    fixture.detectChanges();

    const pills = (fixture.nativeElement as HTMLElement).querySelectorAll('.pill');
    expect([...pills].map((pill) => pill.className)).toEqual(['pill short']);
  });

  it('renders no pill when there are neither tags nor saved searches', () => {
    expect(mount([], []).querySelector('.pill')).toBeNull();
  });

  it('links each tag pill to its tag filter', () => {
    const href = mount([tag(5, 'News')])
      .querySelector('a.pill')!
      .getAttribute('href');
    expect(href).toContain('tag=5');
  });

  it('links each saved-search pill to its slug path', () => {
    const pill = mount([], [savedSearch(2, 'climate')]).querySelector('a.pill.saved-search')!;
    expect(pill.getAttribute('href')).toContain('/searches/saved/2-climate');
  });

  it('stops a pill click from bubbling so the parent entry does not open', () => {
    const element = mount([tag(1, 'News')], [savedSearch(2, 'climate')]);
    const parentClick = jest.fn();
    element.addEventListener('click', parentClick);
    for (const pill of element.querySelectorAll('a.pill')) {
      pill.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    }
    expect(parentClick).not.toHaveBeenCalled();
  });

  describe('network pill', () => {
    function mountFor(subscriptionId: number | null, networks: [number, 'bluesky' | 'mastodon'][]) {
      TestBed.resetTestingModule();
      TestBed.configureTestingModule({
        imports: [EntryPillsComponent, provideTranslocoTesting()],
        providers: [provideRouter([{ path: '**', children: [] }])],
      });
      TestBed.inject(SubscriptionRows).stored.set(
        networks.map(([id, network]) =>
          makeSubscription({ id, social: { network, accountId: 1, kind: 'home' } }),
        ),
      );
      const fixture = TestBed.createComponent(EntryPillsComponent);
      fixture.componentRef.setInput('tags', [tag(1, 'Tech')]);
      fixture.componentRef.setInput('subscriptionId', subscriptionId);
      fixture.detectChanges();
      return fixture.nativeElement as HTMLElement;
    }

    it('leads with the short network name before the tags', () => {
      const element = mountFor(5, [[5, 'bluesky']]);
      const first = element.querySelector('.pill')!;

      expect(first.textContent?.trim()).toBe('bsky');
      expect(first.querySelector('app-network-logo svg.bluesky')).not.toBeNull();
      expect(element.querySelectorAll('.pill')[1].textContent).toContain('Tech');
    });

    it("links to the network's combined view", () => {
      const pill = mountFor(5, [[5, 'mastodon']]).querySelector('a.pill.network')!;

      expect(pill.getAttribute('href')).toContain('network=mastodon');
    });

    it('stops its click from bubbling so the parent entry does not open', () => {
      const element = mountFor(5, [[5, 'bluesky']]);
      const parentClick = jest.fn();
      element.addEventListener('click', parentClick);

      element
        .querySelector('a.pill.network')!
        .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

      expect(parentClick).not.toHaveBeenCalled();
    });

    it('names Mastodon too', () => {
      expect(
        mountFor(5, [[5, 'mastodon']])
          .querySelector('.pill')
          ?.textContent?.trim(),
      ).toBe('mastodon');
    });

    it('shows nothing for an ordinary feed or no subscription', () => {
      expect(mountFor(6, [[5, 'bluesky']]).querySelectorAll('.pill').length).toBe(1);
      expect(mountFor(null, [[5, 'bluesky']]).querySelectorAll('.pill').length).toBe(1);
    });
  });
});
