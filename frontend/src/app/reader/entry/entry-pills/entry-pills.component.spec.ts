import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { TranslocoService } from '@jsverse/transloco';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { EntryPillsComponent } from './entry-pills.component';
import { SubscriptionRows } from '../../state/subscription-rows';
import { makeSubscription } from '../../testing/subscription.factory';
import { SavedSearchMembershipDto, SubscriptionTagDto } from '../../models';
import { INCLUDED_EVERYWHERE } from '../../tag-inclusion';
import { signal } from '@angular/core';
import { anEntry } from '../../../../testing/entry-testing';
import { LabelMenu } from '../../labels/label-menu.service';
import { ListContext } from '../../state/list-context';
import { Selection } from '../../query/query';
import { EntryActionHandler } from '../entry-actions/entry-action-handler';
import { provideEntryActionsContext } from '../../../../testing/entry-actions-testing';
import { EntryDto } from '../../models';

const tag = (id: number, name: string): SubscriptionTagDto => ({
  id,
  name,
  color: null,
  icon: null,
  position: 0,
  parentId: null,
  ...INCLUDED_EVERYWHERE,
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
    providers: [provideRouter([{ path: '**', children: [] }]), ...provideEntryActionsContext()],
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
      providers: [provideRouter([]), ...provideEntryActionsContext()],
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

  describe('social feed pill', () => {
    interface SocialFeedFixture {
      id: number;
      network: 'bluesky' | 'mastodon';
      customTitle?: string;
    }

    function mountFor(subscriptionId: number | null, feeds: SocialFeedFixture[]) {
      TestBed.resetTestingModule();
      TestBed.configureTestingModule({
        imports: [EntryPillsComponent, provideTranslocoTesting()],
        providers: [provideRouter([{ path: '**', children: [] }]), ...provideEntryActionsContext()],
      });
      TestBed.inject(SubscriptionRows).stored.set(
        feeds.map(({ id, network, customTitle }) =>
          makeSubscription({
            id,
            title: customTitle ?? 'stored title',
            customTitle: customTitle ?? null,
            social: { network, accountId: 1, kind: 'home' },
          }),
        ),
      );
      const fixture = TestBed.createComponent(EntryPillsComponent);
      fixture.componentRef.setInput('tags', [tag(1, 'Tech')]);
      fixture.componentRef.setInput('subscriptionId', subscriptionId);
      fixture.detectChanges();
      return fixture.nativeElement as HTMLElement;
    }

    it("leads with the feed's name and its network logo, before the tags", () => {
      const element = mountFor(5, [{ id: 5, network: 'bluesky', customTitle: 'Friends' }]);
      const first = element.querySelector('.pill')!;

      expect(first.textContent?.trim()).toBe('Friends');
      expect(first.getAttribute('title')).toBe('Friends');
      expect(first.querySelector('app-network-logo svg.bluesky')).not.toBeNull();
      expect(element.querySelectorAll('.pill')[1].textContent).toContain('Tech');
    });

    it('names an unrenamed home feed by its localised label', () => {
      const pill = mountFor(5, [{ id: 5, network: 'mastodon' }]).querySelector('.pill')!;

      expect(pill.querySelector('.name')?.textContent).toBe(
        TestBed.inject(TranslocoService).translate('reader.socialHome', { network: 'mastodon' }),
      );
      expect(pill.querySelector('app-network-logo svg.mastodon')).not.toBeNull();
    });

    it('links to the feed', () => {
      const pill = mountFor(5, [{ id: 5, network: 'mastodon' }]).querySelector('a.pill.feed')!;

      expect(pill.getAttribute('href')).toContain('subscription=5');
      expect(pill.getAttribute('href')).not.toContain('network=');
    });

    it('stops its click from bubbling so the parent entry does not open', () => {
      const element = mountFor(5, [{ id: 5, network: 'bluesky' }]);
      const parentClick = jest.fn();
      element.addEventListener('click', parentClick);

      element
        .querySelector('a.pill.feed')!
        .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

      expect(parentClick).not.toHaveBeenCalled();
    });

    it('shows nothing for an ordinary feed or no subscription', () => {
      const feeds: SocialFeedFixture[] = [{ id: 5, network: 'bluesky' }];

      expect(mountFor(6, feeds).querySelectorAll('.pill').length).toBe(1);
      expect(mountFor(null, feeds).querySelectorAll('.pill').length).toBe(1);
    });
  });

  describe('label pills', () => {
    const labels = (count: number) =>
      ['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'].slice(0, count).map((name, index) => ({
        id: index + 1,
        name,
      }));

    function mountLabelled(entry: EntryDto, selection: Selection | null = null) {
      TestBed.resetTestingModule();
      const open = jest.fn();
      const actions = { open: jest.fn() } as unknown as EntryActionHandler;
      TestBed.configureTestingModule({
        imports: [EntryPillsComponent, provideTranslocoTesting()],
        providers: [
          provideRouter([{ path: '**', children: [] }]),
          { provide: LabelMenu, useValue: { open, isOpenAt: () => false } },
          { provide: EntryActionHandler, useValue: actions },
          ...(selection === null
            ? []
            : [{ provide: ListContext, useValue: { selection: signal(selection) } }]),
        ],
      });
      const fixture = TestBed.createComponent(EntryPillsComponent);
      fixture.componentRef.setInput('labelled', entry);
      fixture.detectChanges();
      return { element: fixture.nativeElement as HTMLElement, open, actions };
    }

    const labelPills = (element: HTMLElement) => [...element.querySelectorAll('a.pill.label')];

    it('shows the first three labels and a +1 button for a fourth', () => {
      const { element } = mountLabelled(anEntry({ isFavorite: true, labels: labels(4) }));

      expect(labelPills(element).map((pill) => pill.querySelector('.name')?.textContent)).toEqual([
        'Alpha',
        'Beta',
        'Gamma',
      ]);
      expect(element.querySelector('button.more')?.textContent?.trim()).toBe('+1');
    });

    it('shows no +N button for three labels', () => {
      const { element } = mountLabelled(anEntry({ isFavorite: true, labels: labels(3) }));

      expect(element.querySelector('button.more')).toBeNull();
    });

    it('keeps the order the entry delivers', () => {
      const entry = anEntry({
        isFavorite: true,
        labels: [
          { id: 9, name: 'Zed' },
          { id: 2, name: 'Alpha' },
        ],
      });

      const { element } = mountLabelled(entry);

      expect(labelPills(element).map((pill) => pill.querySelector('.name')?.textContent)).toEqual([
        'Zed',
        'Alpha',
      ]);
    });

    it('links to the kept list of the label inside Kept', () => {
      const { element } = mountLabelled(
        anEntry({ isFavorite: true, isKept: true, labels: labels(1) }),
        {
          kind: 'kept',
        },
      );

      const href = labelPills(element)[0].getAttribute('href')!;
      expect(href).toContain('view=kept');
      expect(href).toContain('label=1');
    });

    it('links to Favorites outside both lists when the entry is a favorite', () => {
      const { element } = mountLabelled(
        anEntry({ isFavorite: true, isKept: true, labels: labels(1) }),
        { kind: 'all', unread: false },
      );

      expect(labelPills(element)[0].getAttribute('href')).toContain('view=favorites');
    });

    it('links to Kept without a list context when the entry is only kept', () => {
      const { element } = mountLabelled(anEntry({ isKept: true, labels: labels(1) }));

      expect(labelPills(element)[0].getAttribute('href')).toContain('view=kept');
    });

    it('shows no label pill on an entry that is neither favorite nor kept', () => {
      const { element } = mountLabelled(anEntry({ labels: labels(2) }));

      expect(labelPills(element)).toEqual([]);
      expect(element.querySelector('button.more')).toBeNull();
    });

    it('opens the label menu from the +N button with the entry, the button and its handler', () => {
      const entry = anEntry({ isFavorite: true, labels: labels(4) });
      const { element, open, actions } = mountLabelled(entry);
      const button = element.querySelector<HTMLButtonElement>('button.more')!;
      const parentClick = jest.fn();
      element.addEventListener('click', parentClick);

      button.click();

      expect(open).toHaveBeenCalledWith(entry, button, actions);
      expect(button.getAttribute('aria-haspopup')).toBe('menu');
      expect(parentClick).not.toHaveBeenCalled();
    });

    it('stops a label pill click from bubbling', () => {
      const { element } = mountLabelled(anEntry({ isFavorite: true, labels: labels(1) }));
      const parentClick = jest.fn();
      element.addEventListener('click', parentClick);

      labelPills(element)[0].dispatchEvent(
        new MouseEvent('click', { bubbles: true, cancelable: true }),
      );

      expect(parentClick).not.toHaveBeenCalled();
    });
  });
});
