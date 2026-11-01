import { ComponentFixture, TestBed } from '@angular/core/testing';
import { WritableSignal, signal } from '@angular/core';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { anEntry } from '../../../../testing/entry-testing';
import { LanguageService } from '../../../core/i18n/language.service';
import { Lang } from '../../../core/i18n/language';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { EntrySocialDto, PostActionsView, SocialCountsDto } from '../../entry-social';
import { ToastService } from '../../../shared/toast/toast.service';
import { Problem } from '../../../core/problem';
import { EntryDto } from '../../models';
import { ReplyActions } from '../composer/reply-actions.service';
import { SocialCountsComponent } from './social-counts.component';
import { SocialCountsService, SocialCountsState } from './social-counts.service';

const COUNTS: SocialCountsDto = { likes: 41, reposts: 5, replies: 7, quotes: 2 };

describe('SocialCountsComponent', () => {
  let fixture: ComponentFixture<SocialCountsComponent>;
  let states: Map<number, WritableSignal<SocialCountsState>>;
  let service: { state: jest.Mock; load: jest.Mock; toggleRepost: jest.Mock };
  let toast: { show: jest.Mock };
  let actions: { like: jest.Mock };
  let replies: { reply: jest.Mock };
  let language: { lang: WritableSignal<Lang> };

  beforeEach(() => {
    states = new Map();
    service = {
      state: jest.fn((id: number) => stateOf(id)),
      load: jest.fn(),
      toggleRepost: jest.fn(),
    };
    toast = { show: jest.fn() };
    actions = { like: jest.fn() };
    replies = { reply: jest.fn() };
    language = { lang: signal<Lang>('en') };
    TestBed.configureTestingModule({
      imports: [SocialCountsComponent, provideTranslocoTesting()],
      providers: [
        { provide: SocialCountsService, useValue: service },
        { provide: EntryActionHandler, useValue: actions },
        { provide: LanguageService, useValue: language },
        { provide: ToastService, useValue: toast },
        { provide: ReplyActions, useValue: replies },
      ],
    });
    fixture = TestBed.createComponent(SocialCountsComponent);
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
    actions: PostActionsView | null = null,
  ): SocialCountsState => ({
    status: 'ok',
    counts: { ...COUNTS, ...counts },
    likedAtLoad,
    actions,
    loadedAt: 0,
  });

  function show(entry: EntryDto): HTMLElement {
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
  const repostButton = (element: HTMLElement) =>
    element.querySelector('app-repost-button button.repost') as HTMLButtonElement | null;
  const likeNumber = (element: HTMLElement) =>
    likeButton(element)?.querySelector('.number')?.textContent?.trim() ?? null;

  it('loads as soon as the post opens', () => {
    const entry = post();
    show(entry);

    expect(service.load).toHaveBeenCalledWith({ id: 1, social: entry.social });
  });

  it('loads a post once, however often its like changes', () => {
    show(post());
    show(post({ isLiked: true }));

    expect(service.load).toHaveBeenCalledTimes(1);
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

  describe('reply', () => {
    const replyButton = (element: HTMLElement) =>
      element.querySelector('button.reply') as HTMLButtonElement | null;

    it('opens the composer for the post from the replies count', () => {
      const entry = anEntry({
        id: 1,
        excerpt: 'Big news',
        author: 'Bob',
        social: { network: 'bluesky', isLiked: false, canLike: true },
      });
      show(entry);
      const button = replyButton(
        settle(loaded({}, false, { isReposted: false, canRepost: true, canReply: true })),
      )!;

      expect(button.getAttribute('aria-label')).toBe('Reply on Bluesky, 7 replies');
      button.click();

      expect(replies.reply).toHaveBeenCalledWith({
        entryId: 1,
        network: 'bluesky',
        address: null,
        authorName: 'Bob',
        authorHandle: null,
        excerpt: 'Big news',
      });
    });

    it('offers the reply disabled, with its reason, on a post the author closed', () => {
      show(post());
      const button = replyButton(
        settle(loaded({}, false, { isReposted: false, canRepost: true, canReply: false })),
      )!;
      button.click();

      expect(button.getAttribute('aria-disabled')).toBe('true');
      expect(button.title).toBe('The author limits who can reply');
      expect(replies.reply).not.toHaveBeenCalled();
    });

    it('stays a plain count until the network says what the reader may do', () => {
      show(post());

      expect(replyButton(settle(loaded()))).toBeNull();
    });
  });

  describe('repost', () => {
    it('stays a plain count until the network says what the reader did', () => {
      show(post());
      const element = settle(loaded());

      expect(repostButton(element)).toBeNull();
      expect(labels(element)[0]).toBe('5 reposts');
    });

    it('turns the repost count into the first button once the network answers', () => {
      show(post());
      const element = settle(
        loaded({}, false, { isReposted: false, canRepost: true, canReply: true }),
      );
      const button = repostButton(element)!;

      expect(labels(element)).toEqual(['2 quotes']);
      expect(element.querySelector('.row')!.firstElementChild?.tagName).toBe('APP-REPOST-BUTTON');
      expect(button.querySelector('.number')?.textContent?.trim()).toBe('5');
      expect(button.getAttribute('aria-label')).toBe('Repost on Bluesky, 5 reposts');
      expect(button.classList).not.toContain('on');
    });

    it('marks a reposted post', () => {
      show(post({ network: 'mastodon' }));
      const button = repostButton(
        settle(loaded({}, false, { isReposted: true, canRepost: true, canReply: true })),
      )!;

      expect(button.getAttribute('aria-label')).toBe('Undo boost on Mastodon, 5 boosts');
      expect(button.classList).toContain('on');
    });

    it('reposts through the counts service and toasts what the network refused', () => {
      const entry = post();
      show(entry);
      const element = settle(
        loaded({}, false, { isReposted: false, canRepost: true, canReply: true }),
      );
      repostButton(element)!.click();
      fixture.detectChanges();

      const item = document.querySelector('[role="menuitem"]') as HTMLButtonElement;
      expect(item.textContent).toContain('Repost on Bluesky');
      item.click();

      expect(service.toggleRepost).toHaveBeenCalledWith(entry, expect.any(Function));
      const onError = service.toggleRepost.mock.calls[0][1] as (problem: Problem) => void;
      onError({ status: 409, type: 'social_post_refused', title: 'Refused', detail: 'No boosts' });
      expect(toast.show).toHaveBeenCalledWith({ message: 'No boosts' });
    });
  });
});
