import { ComponentFixture, TestBed } from '@angular/core/testing';
import { WritableSignal, signal } from '@angular/core';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { anEntry } from '../../../../testing/entry-testing';
import { LanguageService } from '../../../core/i18n/language.service';
import { Lang } from '../../../core/i18n/language';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { EntrySocialDto, SocialCountsDto } from '../../entry-social';
import { prefetchMargin } from '../../list/paging';
import { EntryDto } from '../../models';
import { SocialCountsComponent } from './social-counts.component';
import { SocialCountsService, SocialCountsState } from './social-counts.service';

const COUNTS: SocialCountsDto = { likes: 41, reposts: 5, replies: 7, quotes: 2 };

describe('SocialCountsComponent', () => {
  let fixture: ComponentFixture<SocialCountsComponent>;
  let states: Map<number, WritableSignal<SocialCountsState>>;
  let service: { state: jest.Mock; load: jest.Mock };
  let actions: { like: jest.Mock };
  let language: { lang: WritableSignal<Lang> };
  let observers: { init: IntersectionObserverInit | undefined }[];
  let trigger: ((visible: boolean) => void) | undefined;
  let scroller: HTMLElement;
  const realIntersectionObserver = globalThis.IntersectionObserver;

  beforeEach(() => {
    states = new Map();
    service = { state: jest.fn((id: number) => stateOf(id)), load: jest.fn() };
    actions = { like: jest.fn() };
    language = { lang: signal<Lang>('en') };
    observers = [];
    trigger = undefined;
    scroller = document.createElement('div');
    Object.defineProperty(scroller, 'clientHeight', { value: 900 });
    globalThis.IntersectionObserver = jest.fn(
      (callback: IntersectionObserverCallback, init?: IntersectionObserverInit) => {
        const observer = { observe: jest.fn(), disconnect: jest.fn() };
        observers.push({ init });
        trigger = (visible) =>
          callback(
            [{ isIntersecting: visible } as IntersectionObserverEntry],
            observer as unknown as IntersectionObserver,
          );
        return observer;
      },
    ) as unknown as typeof IntersectionObserver;
    TestBed.configureTestingModule({
      imports: [SocialCountsComponent, provideTranslocoTesting()],
      providers: [
        { provide: SocialCountsService, useValue: service },
        { provide: EntryActionHandler, useValue: actions },
        { provide: LanguageService, useValue: language },
      ],
    });
    fixture = TestBed.createComponent(SocialCountsComponent);
  });

  afterEach(() => {
    globalThis.IntersectionObserver = realIntersectionObserver;
  });

  function stateOf(id: number): WritableSignal<SocialCountsState> {
    const existing = states.get(id);
    if (existing) return existing;
    const created = signal<SocialCountsState>({ status: 'idle' });
    states.set(id, created);
    return created;
  }

  const post = (social: Partial<EntrySocialDto> = {}): EntryDto =>
    anEntry({ id: 1, social: { network: 'bluesky', isLiked: false, canLike: true, ...social } });

  const loaded = (
    counts: Partial<SocialCountsDto> = {},
    likedAtLoad = false,
  ): SocialCountsState => ({
    status: 'ok',
    counts: { ...COUNTS, ...counts },
    likedAtLoad,
    loadedAt: 0,
  });

  function show(entry: EntryDto): HTMLElement {
    fixture.componentRef.setInput('scroller', scroller);
    fixture.componentRef.setInput('entry', entry);
    fixture.detectChanges();
    return fixture.nativeElement;
  }

  function settle(state: SocialCountsState): HTMLElement {
    stateOf(1).set(state);
    fixture.detectChanges();
    return fixture.nativeElement;
  }

  const labels = (element: HTMLElement) =>
    Array.from(element.querySelectorAll('.count')).map((count) => count.getAttribute('aria-label'));
  const likeButton = (element: HTMLElement) =>
    element.querySelector('button.like') as HTMLButtonElement | null;
  const likeNumber = (element: HTMLElement) =>
    likeButton(element)?.querySelector('.number')?.textContent?.trim() ?? null;

  it('loads only once the row nears the viewport', () => {
    const entry = post();
    show(entry);
    expect(service.load).not.toHaveBeenCalled();

    trigger!(false);
    expect(service.load).not.toHaveBeenCalled();
    trigger!(true);

    expect(service.load).toHaveBeenCalledWith({ id: 1, social: entry.social });
  });

  it('looks ahead within the reader scroller', () => {
    show(post());

    expect(observers[0].init).toEqual({ root: scroller, rootMargin: prefetchMargin(900) });
  });

  it('offers the like alone until the counts arrive', () => {
    const element = show(post());

    expect(element.querySelectorAll('.count')).toHaveLength(0);
    expect(likeButton(element)).not.toBeNull();
    expect(likeNumber(element)).toBeNull();
  });

  it('shows nothing for a post the user cannot like until the counts arrive', () => {
    expect(show(post({ canLike: false })).querySelector('.row')).toBeNull();
  });

  it('orders reposts, replies and quotes, then the like at the right end', () => {
    show(post());
    const element = settle(loaded());

    expect(labels(element)).toEqual(['5 reposts', '7 replies', '2 quotes']);
    expect(element.querySelector('.row')!.lastElementChild).toBe(likeButton(element));
    expect(likeNumber(element)).toBe('41');
  });

  it('calls Mastodon reposts boosts and shows no quotes there', () => {
    show(post({ network: 'mastodon' }));

    expect(labels(settle(loaded({ quotes: null })))).toEqual(['5 boosts', '7 replies']);
  });

  it('words a count of one in the singular', () => {
    show(post());

    expect(labels(settle(loaded({ replies: 1 })))[1]).toBe('1 reply');
  });

  it('compacts large numbers in the active language', () => {
    show(post());
    const element = settle(loaded({ likes: 1234 }));
    expect(likeNumber(element)).toBe('1.2K');

    language.lang.set('de');
    fixture.detectChanges();

    expect(likeNumber(element)).toBe('1234');
  });

  it('moves the like number with the like, and back with its rollback', () => {
    show(post());
    settle(loaded());

    expect(likeNumber(show(post({ isLiked: true })))).toBe('42');
    expect(likeNumber(show(post({ isLiked: false })))).toBe('41');
  });

  it('counts down from a like that was already in the loaded number', () => {
    show(post({ isLiked: true }));
    settle(loaded({}, true));

    expect(likeNumber(show(post({ isLiked: true })))).toBe('41');
    expect(likeNumber(show(post({ isLiked: false })))).toBe('40');
  });

  it('keeps the like button and hides every number when the counts fail', () => {
    show(post());
    const element = settle({ status: 'failed', failedAt: 0 });

    expect(element.querySelectorAll('.count')).toHaveLength(0);
    expect(likeButton(element)).not.toBeNull();
    expect(likeNumber(element)).toBeNull();
  });

  it('shows the like count as a plain count to a user without an account there', () => {
    show(post({ canLike: false }));
    const element = settle(loaded());

    expect(likeButton(element)).toBeNull();
    expect(labels(element)).toEqual(['5 reposts', '7 replies', '2 quotes', '41 likes']);
  });

  it('likes through the entry actions, named and hinted for its network', () => {
    const entry = post();
    const element = show(entry);
    const button = likeButton(element)!;

    expect(button.getAttribute('aria-label')).toBe('Like on Bluesky');
    expect(button.getAttribute('title')).toBe('Like on Bluesky — a public like the author sees');
    expect(button.getAttribute('aria-pressed')).toBe('false');
    button.click();

    expect(actions.like).toHaveBeenCalledWith(entry);
  });

  it('marks a liked post pressed and filled', () => {
    const button = likeButton(show(post({ network: 'mastodon', isLiked: true })))!;

    expect(button.getAttribute('aria-label')).toBe('Unlike on Mastodon');
    expect(button.getAttribute('aria-pressed')).toBe('true');
    expect(button.classList).toContain('on');
  });
});
