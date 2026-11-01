import { ComponentFixture, TestBed } from '@angular/core/testing';
import { WritableSignal, signal } from '@angular/core';
import { EntryCommentsComponent } from './entry-comments.component';
import { ResponseActions } from '../composer/response-actions.service';
import { CommentsService, CommentsState } from './comments.service';
import { ImageProxyService } from '../../../shared/proxied-image/image-proxy.service';
import { CommentsLoad } from '../../models';
import { EntrySocialDto } from '../../entry-social';
import { EntryCommentDto, EntryCommentPostDto } from '../../entry-comment';
import { LinkReaderNavigation } from '../../shell/link-reader-navigation.service';
import { aLinkCard, anEntryComment, aQuotedPost } from '../../../../testing/entry-testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';

const DISCUSSION = 'https://www.reddit.com/r/x/comments/1/t/';

const FETCHED_AT = '2026-09-24T12:00:00Z';

const BLUESKY: EntrySocialDto = { network: 'bluesky', isLiked: false, canLike: true };

const navigation = { layer: jest.fn() };

describe('EntryCommentsComponent', () => {
  let fixture: ComponentFixture<EntryCommentsComponent>;
  let states: Map<number, WritableSignal<CommentsState>>;
  let responses: { respond: jest.Mock; delete: jest.Mock };
  let service: {
    state: jest.Mock;
    load: jest.Mock;
    reload: jest.Mock;
    toggleLike: jest.Mock;
    toggleRepost: jest.Mock;
  };

  beforeEach(() => {
    responses = { respond: jest.fn(), delete: jest.fn() };
    states = new Map();
    service = {
      state: jest.fn((id: number) => stateOf(id)),
      load: jest.fn(),
      reload: jest.fn(),
      toggleLike: jest.fn(),
      toggleRepost: jest.fn(),
    };
    navigation.layer.mockReset();
    TestBed.configureTestingModule({
      imports: [EntryCommentsComponent, provideTranslocoTesting()],
      providers: [
        { provide: CommentsService, useValue: service },
        { provide: ResponseActions, useValue: responses },
        { provide: LinkReaderNavigation, useValue: navigation },
        { provide: ImageProxyService, useValue: { recover: () => Promise.resolve('failed') } },
      ],
    });
    fixture = TestBed.createComponent(EntryCommentsComponent);
  });

  function stateOf(id: number): WritableSignal<CommentsState> {
    const existing = states.get(id);
    if (existing) return existing;
    const created = signal<CommentsState>({ status: 'idle' });
    states.set(id, created);
    return created;
  }

  function show(comments: CommentsLoad, id = 5, discussionUrl: string | null = DISCUSSION): void {
    showEntry({ id, comments, discussionUrl, social: null });
  }

  function showEntry(entry: {
    id: number;
    comments: CommentsLoad;
    discussionUrl: string | null;
    social: EntrySocialDto | null;
  }): void {
    fixture.componentRef.setInput('entry', entry);
    fixture.detectChanges();
  }

  function settleComments(comments: EntryCommentDto[], omitted = 0): HTMLElement {
    return settle({ status: 'ok', comments, omitted, fetchedAt: FETCHED_AT, loadedAt: Date.now() });
  }

  function settle(state: CommentsState): HTMLElement {
    stateOf(5).set(state);
    fixture.detectChanges();
    return fixture.nativeElement;
  }

  describe('a comment that is a network post', () => {
    const post: EntryCommentPostDto = {
      address: 'at://did:plc:ann/app.bsky.feed.post/2',
      counts: { likes: 4, reposts: 1, replies: 2, quotes: 0 },
      isLiked: false,
      isReposted: false,
      canRepost: true,
      canReply: true,
      canQuote: true,
      isOwn: false,
    };
    const likeButton = (element: HTMLElement) =>
      element.querySelector('.comment button.like') as HTMLButtonElement | null;

    it('shows its counters and a like button with its likes', () => {
      showEntry({ id: 5, comments: 'manual', discussionUrl: DISCUSSION, social: BLUESKY });
      const element = settleComments([anEntryComment({ post })]);

      expect(element.querySelectorAll('.comment .count')).toHaveLength(0);
      expect(element.querySelector('.comment button.quote')).not.toBeNull();
      expect(element.querySelector('.comment button.repost .number')?.textContent?.trim()).toBe(
        '1',
      );
      expect(likeButton(element)?.querySelector('.number')?.textContent?.trim()).toBe('4');
    });

    it('reposts the comment through the service from its repost menu', () => {
      showEntry({ id: 5, comments: 'manual', discussionUrl: DISCUSSION, social: BLUESKY });
      const element = settleComments([anEntryComment({ post })]);
      (element.querySelector('.comment button.repost') as HTMLButtonElement).click();
      fixture.detectChanges();
      (document.querySelector('[role="menuitem"]') as HTMLButtonElement).click();

      expect(service.toggleRepost).toHaveBeenCalledWith(5, post, expect.any(Function));
    });

    it('quotes the comment from its repost menu', () => {
      showEntry({ id: 5, comments: 'manual', discussionUrl: DISCUSSION, social: BLUESKY });
      const element = settleComments([anEntryComment({ post, author: 'Ann', html: '<p>Yes</p>' })]);
      (element.querySelector('.comment button.repost') as HTMLButtonElement).click();
      fixture.detectChanges();
      document.querySelectorAll<HTMLButtonElement>('[role="menuitem"]')[1].click();

      expect(responses.respond).toHaveBeenCalledWith(
        expect.objectContaining({
          kind: 'quote',
          entryId: 5,
          address: post.address,
          excerpt: 'Yes',
        }),
      );
    });

    it('likes the comment through the service', () => {
      showEntry({ id: 5, comments: 'manual', discussionUrl: DISCUSSION, social: BLUESKY });
      likeButton(settleComments([anEntryComment({ post })]))?.click();

      expect(service.toggleLike).toHaveBeenCalledWith(5, post, expect.any(Function));
    });

    it('counts likes without a button when the reader has no account to like with', () => {
      const social = { ...BLUESKY, canLike: false };
      showEntry({ id: 5, comments: 'manual', discussionUrl: DISCUSSION, social });
      const element = settleComments([anEntryComment({ post })]);

      expect(likeButton(element)).toBeNull();
      expect(element.querySelectorAll('.comment .count')).toHaveLength(4);
    });

    it('opens the composer for the comment from its replies count', () => {
      showEntry({ id: 5, comments: 'manual', discussionUrl: DISCUSSION, social: BLUESKY });
      const comment = anEntryComment({
        post,
        author: 'Ann',
        authorHandle: '@ann.bsky.social',
        html: '<p>Is it <b>true</b>?</p>',
      });
      (
        settleComments([comment]).querySelector('.comment button.reply') as HTMLButtonElement
      ).click();

      expect(responses.respond).toHaveBeenCalledWith({
        kind: 'reply',
        entryId: 5,
        network: 'bluesky',
        address: post.address,
        authorName: 'Ann',
        authorHandle: '@ann.bsky.social',
        excerpt: 'Is it true?',
        card: null,
      });
    });

    it('offers to delete only a reply the reader wrote', () => {
      showEntry({ id: 5, comments: 'manual', discussionUrl: DISCUSSION, social: BLUESKY });
      const own = anEntryComment({ post: { ...post, isOwn: true } });
      const element = settleComments([own, anEntryComment({ post })]);
      const deletes = element.querySelectorAll('.comment button.delete');

      expect(deletes).toHaveLength(1);
      (deletes[0] as HTMLButtonElement).click();
      expect(responses.delete).toHaveBeenCalledWith(5, 'bluesky', own);
    });

    it('shows no row for a comment from a feed', () => {
      showEntry({ id: 5, comments: 'manual', discussionUrl: DISCUSSION, social: BLUESKY });

      expect(settleComments([anEntryComment()]).querySelector('app-social-counts-row')).toBeNull();
    });
  });

  it('auto-loads as soon as it shows, so the header can count the comments (#1740)', () => {
    show('auto');
    expect(service.load).toHaveBeenCalledWith(5);
  });

  it('loads the next entry when the reader moves on', () => {
    show('auto');
    show('auto', 6);
    expect(service.load).toHaveBeenLastCalledWith(6);
  });

  it('never auto-loads a manual source; the button loads it', () => {
    show('manual');
    expect(service.load).not.toHaveBeenCalled();
    fixture.nativeElement.querySelector('button.load').click();
    expect(service.load).toHaveBeenCalledWith(5);
  });

  it('offers no load button for an auto source', () => {
    show('auto');
    expect(fixture.nativeElement.querySelector('button.load')).toBeNull();
  });

  it('shows a skeleton while loading', () => {
    show('auto');
    const element = settle({ status: 'loading' });
    expect(element.querySelector('app-skeleton')).not.toBeNull();
    expect(element.querySelector('section')?.getAttribute('aria-busy')).toBe('true');
  });

  it('renders comments with the OP pill and no count', () => {
    show('auto');
    const element = settle({
      status: 'ok',
      omitted: 0,
      fetchedAt: FETCHED_AT,
      loadedAt: Date.now(),
      comments: [
        anEntryComment({
          author: '/u/op',
          authorUrl: 'https://www.reddit.com/user/op',
          url: 'https://c/1',
          publishedAt: new Date(Date.now() - 3 * 3_600_000).toISOString(),
          html: '<p>Hi</p>',
          byEntryAuthor: true,
        }),
        anEntryComment({ author: '/u/b', html: '<p>Yo</p>' }),
      ],
    });
    const rows = element.querySelectorAll('.comment');
    expect(rows.length).toBe(2);
    expect(element.querySelectorAll('.op').length).toBe(1);
    expect(element.querySelector('.count')).toBeNull();
    expect(rows[0].querySelector('a.author')?.getAttribute('href')).toBe(
      'https://www.reddit.com/user/op',
    );
    expect(rows[0].querySelector('a.when')?.getAttribute('href')).toBe('https://c/1');
    expect(rows[0].querySelector('a.when')?.textContent?.trim()).toBe('3 hr. ago');
    expect(rows[0].querySelector('.body')?.innerHTML).toBe('<p>Hi</p>');
    expect(rows[1].querySelector('a.author')).toBeNull();
    expect(rows[1].querySelector('.when')).toBeNull();
    expect(element.querySelector('a.all')?.getAttribute('href')).toBe(DISCUSSION);
  });

  it('shows the empty line for zero comments', () => {
    show('auto');
    const element = settle({
      status: 'ok',
      comments: [],
      omitted: 0,
      fetchedAt: FETCHED_AT,
      loadedAt: Date.now(),
    });
    expect(element.querySelector('.empty')?.textContent?.trim()).toBe('No comments yet.');
  });

  it('drops the closing link without a discussion URL', () => {
    show('manual', 5, null);
    const element = settle({
      status: 'ok',
      comments: [],
      omitted: 0,
      fetchedAt: FETCHED_AT,
      loadedAt: Date.now(),
    });
    expect(element.querySelector('a.all')).toBeNull();
  });

  it('reload calls the service', () => {
    show('auto');
    const element = settle({
      status: 'ok',
      comments: [],
      omitted: 0,
      fetchedAt: FETCHED_AT,
      loadedAt: Date.now(),
    });
    const reload = element.querySelector<HTMLButtonElement>('button.reload')!;
    expect(reload.getAttribute('aria-label')).toBe('Reload comments');
    reload.click();
    expect(service.reload).toHaveBeenCalledWith(5);
  });

  it('indents a reply by its depth', () => {
    show('auto');
    const element = settleComments([anEntryComment(), anEntryComment({ depth: 2 })]);
    const rows = element.querySelectorAll<HTMLElement>('.comment');
    expect(rows[0].style.getPropertyValue('--depth')).toBe('0');
    expect(rows[1].style.getPropertyValue('--depth')).toBe('2');
  });

  it('caps the indent of a deep reply', () => {
    show('auto');
    const element = settleComments([anEntryComment({ depth: 9 })]);
    expect(element.querySelector<HTMLElement>('.comment')!.style.getPropertyValue('--depth')).toBe(
      '5',
    );
  });

  it('shows the author avatar and handle', () => {
    show('auto');
    const element = settleComments([
      anEntryComment({
        author: 'Ann Poster',
        authorHandle: '@ann.bsky.social',
        authorAvatarUrl: 'https://cdn.test/ann.jpg',
      }),
    ]);
    const avatar = element.querySelector('img.avatar')!;
    expect(avatar.getAttribute('src')).toBe('https://cdn.test/ann.jpg');
    expect(avatar.getAttribute('referrerpolicy')).toBe('no-referrer');
    expect(element.querySelector('.handle')?.textContent?.trim()).toBe('@ann.bsky.social');
  });

  it('falls back to the initials when the avatar fails', async () => {
    show('auto');
    const element = settleComments([
      anEntryComment({
        author: 'Ann Poster',
        authorHandle: '@ann.bsky.social',
        authorAvatarUrl: 'https://cdn.test/ann.jpg',
      }),
    ]);
    element.querySelector('img.avatar')!.dispatchEvent(new Event('error'));
    await Promise.resolve();
    fixture.detectChanges();
    expect(element.querySelector('img.avatar')).toBeNull();
    expect(element.querySelector('.avatar.initials')?.textContent?.trim()).toBe('AP');
  });

  it('shows no avatar for a comment without one', () => {
    show('auto');
    const element = settleComments([anEntryComment({ author: '/u/b' })]);
    expect(element.querySelector('.avatar')).toBeNull();
    expect(element.querySelector('.handle')).toBeNull();
  });

  it("renders a reply's media, quote and link card", () => {
    show('auto');
    const element = settleComments([
      anEntryComment({
        media: [{ url: 'https://cdn.test/1.jpg', kind: 'image', width: 800, height: 600 }],
        quote: aQuotedPost(),
        linkCard: aLinkCard(),
      }),
    ]);
    const row = element.querySelector('.comment')!;
    expect(row.querySelector('app-post-media')).not.toBeNull();
    expect(row.querySelector('app-quoted-post')).not.toBeNull();
    expect(row.querySelector('app-link-card')).not.toBeNull();
  });

  it('opens a comment’s link card in the reader layer', () => {
    show('auto');
    const card = aLinkCard({ url: 'https://news.test/from-a-comment' });
    const element = settleComments([anEntryComment({ linkCard: card })]);

    element.querySelector<HTMLButtonElement>('app-link-card button.open-in-reader')!.click();

    expect(navigation.layer).toHaveBeenCalledWith(card);
  });

  it('renders no embeds for a plain comment', () => {
    show('auto');
    const row = settleComments([anEntryComment()]).querySelector('.comment')!;
    expect(row.querySelector('app-post-media, app-quoted-post, app-link-card')).toBeNull();
  });

  it('says how old the comments are', () => {
    show('auto');
    const element = settleComments([]);
    expect(element.querySelector('.age')?.textContent).toContain('Updated');
  });

  it('refreshes the comments and says so, for the counts to follow', () => {
    const refreshed = jest.fn();
    fixture.componentInstance.refreshed.subscribe(refreshed);
    show('auto');
    settleComments([]);

    fixture.nativeElement.querySelector('button.reload').click();

    expect(service.reload).toHaveBeenCalledWith(5);
    expect(refreshed).toHaveBeenCalledTimes(1);
  });

  it('retries without asking the counts to follow', () => {
    const refreshed = jest.fn();
    fixture.componentInstance.refreshed.subscribe(refreshed);
    show('auto');
    settle({ status: 'failed', failedAt: Date.now() });

    fixture.nativeElement.querySelector('button.retry').click();

    expect(service.reload).toHaveBeenCalledWith(5);
    expect(refreshed).not.toHaveBeenCalled();
  });

  it('names the network and the count when replies were cut', () => {
    showEntry({ id: 5, comments: 'auto', discussionUrl: DISCUSSION, social: BLUESKY });
    const element = settleComments([anEntryComment()], 3);
    const all = element.querySelector('a.all')!;
    expect(all.textContent).toContain('3 more on Bluesky');
    expect(all.getAttribute('href')).toBe(DISCUSSION);
  });

  it('names Mastodon for a toot whose replies were cut', () => {
    showEntry({
      id: 5,
      comments: 'auto',
      discussionUrl: DISCUSSION,
      social: { network: 'mastodon', isLiked: false, canLike: false },
    });
    const element = settleComments([anEntryComment()], 1);
    expect(element.querySelector('a.all')?.textContent).toContain('1 more on Mastodon');
  });

  it('keeps the plain link when nothing was cut', () => {
    showEntry({ id: 5, comments: 'auto', discussionUrl: DISCUSSION, social: BLUESKY });
    const element = settleComments([anEntryComment()]);
    expect(element.querySelector('a.all')?.textContent).toContain('All comments');
  });

  describe('throttled', () => {
    beforeEach(() => jest.useFakeTimers({ now: new Date('2026-09-24T12:00:00Z') }));
    afterEach(() => jest.useRealTimers());

    it('counts down to the retry and retries through reload', () => {
      show('auto');
      const element = settle({ status: 'throttled', retryAt: Date.now() + 30_000 });
      const box = element.querySelector('app-warning-box')!;
      expect(box.textContent).toContain('Try again in 30 s.');

      jest.advanceTimersByTime(29_000);
      fixture.detectChanges();
      expect(box.textContent).toContain('Try again in 1 s.');

      jest.advanceTimersByTime(1_000);
      fixture.detectChanges();
      expect(box.textContent).toContain('Try again now.');
      expect(box.querySelector('a')?.getAttribute('href')).toBe(DISCUSSION);

      box.querySelector<HTMLButtonElement>('button.retry')!.click();
      expect(service.reload).toHaveBeenCalledWith(5);
    });

    it('stops ticking once the countdown reaches zero', () => {
      const start = jest.spyOn(globalThis, 'setInterval');
      const stop = jest.spyOn(globalThis, 'clearInterval');
      show('auto');
      settle({ status: 'throttled', retryAt: Date.now() + 2_000 });
      expect(start).toHaveBeenCalledTimes(1);
      const ticker = start.mock.results[0].value;

      jest.advanceTimersByTime(1_000);
      fixture.detectChanges();
      expect(stop).not.toHaveBeenCalledWith(ticker);

      jest.advanceTimersByTime(1_000);
      fixture.detectChanges();
      expect(stop).toHaveBeenCalledWith(ticker);
    });
  });

  it('shows a quiet failure with a retry', () => {
    show('auto');
    const element = settle({ status: 'failed', failedAt: Date.now() });
    const failed = element.querySelector('.failed')!;
    expect(failed.textContent).toContain('Comments could not be loaded.');
    expect(failed.querySelector('a')?.getAttribute('href')).toBe(DISCUSSION);
    expect(element.querySelector('app-warning-box')).toBeNull();
    failed.querySelector<HTMLButtonElement>('button.retry')!.click();
    expect(service.reload).toHaveBeenCalledWith(5);
  });
});
