import { EMPTY } from 'rxjs';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { EntryMetaComponent } from './entry-meta.component';
import { EntryDto, SubscriptionTagDto } from '../../models';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { SHORT_PLAYER_URL } from '../../../../testing/short-marking-testing';
import { SubscriptionRows } from '../../state/subscription-rows';
import { makeSubscription } from '../../testing/subscription.factory';
import { anEntry } from '../../../../testing/entry-testing';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';
import { provideEntryActionsContext } from '../../../../testing/entry-actions-testing';
import { INCLUDED_EVERYWHERE } from '../../tag-inclusion';
import { CommentTotals } from '../../state/comment-totals';

const entryActions = {
  favorite: jest.fn(),
  keep: jest.fn(),
  toggleRead: jest.fn(),
  open: jest.fn(),
  vote: jest.fn(),
};

beforeEach(() => {
  Object.values(entryActions).forEach((spy) => spy.mockReset());
  entryActions.vote.mockReturnValue(EMPTY);
});

const tag = (id: number, name: string): SubscriptionTagDto => ({
  id,
  name,
  color: null,
  icon: null,
  position: 0,
  parentId: null,
  ...INCLUDED_EVERYWHERE,
});

function mount(tags: SubscriptionTagDto[], testEntry: EntryDto = anEntry(), imageShown?: boolean) {
  TestBed.configureTestingModule({
    imports: [EntryMetaComponent, provideTranslocoTesting()],
    providers: [
      provideAccountIdentity(),
      ...provideEntryActionsContext(),
      { provide: EntryActionHandler, useValue: entryActions },
      provideRouter([]),
    ],
  });
  const fixture = TestBed.createComponent(EntryMetaComponent);
  fixture.componentRef.setInput('entry', testEntry);
  fixture.componentRef.setInput('tags', tags);
  if (imageShown !== undefined) fixture.componentRef.setInput('imageShown', imageShown);
  fixture.detectChanges();
  return fixture;
}

describe('EntryMetaComponent', () => {
  it('votesFromTheRow', () => {
    const votable = anEntry({ score: 5, myVote: 0 });
    const element = mount([], votable).nativeElement as HTMLElement;

    (element.querySelector('button[aria-label="Downvote"]') as HTMLButtonElement).click();

    expect(entryActions.vote).toHaveBeenCalledWith(votable, -1);
  });

  describe('discussion counts', () => {
    const discussionMeta = (overrides: Partial<EntryDto>) =>
      mount([], anEntry(overrides)).nativeElement as HTMLElement;

    it('shows the score and comment count first', () => {
      const element = discussionMeta({ score: 1284, commentCount: 212 });
      const discussion = element.firstElementChild as HTMLElement;
      expect(discussion.classList).toContain('discussion');
      expect(discussion.querySelector('app-vote-pill')).not.toBeNull();
      const comments = discussion.querySelector('.comments') as HTMLElement;
      expect(comments.textContent).toContain('212');
      expect(comments.querySelector('app-icon')?.getAttribute('name')).toBe('chat_bubble');
      expect(comments.getAttribute('aria-label')).toBe('212 comments');
      expect(discussion.nextElementSibling?.tagName).toBe('APP-ENTRY-PILLS');
      expect(element.lastElementChild?.tagName).toBe('APP-ENTRY-ACTIONS');
    });

    it('shows the vote pill alone without a comment count', () => {
      const element = discussionMeta({ score: 5, commentCount: null });
      expect(element.querySelector('app-vote-pill')).not.toBeNull();
      expect(element.querySelector('.comments')).toBeNull();
    });

    it('words a single comment in the singular', () => {
      const comments = discussionMeta({ score: 5, commentCount: 1 }).querySelector('.comments');
      expect(comments?.getAttribute('aria-label')).toBe('1 comment');
    });

    it('shows a zero comment count', () => {
      const comments = discussionMeta({ score: 5, commentCount: 0 }).querySelector('.comments');
      expect(comments?.textContent).toContain('0');
    });

    it('shows the comment count alone without a score', () => {
      const element = discussionMeta({ score: null, commentCount: 3 });
      expect(element.querySelector('app-vote-pill')).toBeNull();
      expect(element.querySelector('.discussion .comments')?.textContent).toContain('3');
    });

    it('shows nothing without a score, a like or a comment count', () => {
      expect(
        discussionMeta({
          score: null,
          commentCount: null,
          likeCount: null,
          myVote: 0,
        }).querySelector('.discussion'),
      ).toBeNull();
    });

    it('hands the like to the actions, first and count-first', () => {
      const element = discussionMeta({
        score: null,
        commentCount: 3,
        likeCount: 47,
        social: { network: 'bluesky', isLiked: false, canLike: true, isOwn: false },
      });
      const actions = element.querySelector('app-entry-actions') as HTMLElement;
      const like = actions.firstElementChild as HTMLElement;
      expect(element.querySelector('.discussion app-entry-like')).toBeNull();
      expect(like.tagName).toBe('APP-ENTRY-LIKE');
      expect(like.querySelectorAll('app-entry-like').length).toBe(0);
      expect(actions.querySelectorAll('app-entry-like').length).toBe(1);
      expect(like.classList).toContain('count-start');
      expect(like.querySelectorAll('.count').length).toBe(1);
      expect(like.querySelector('button')?.getAttribute('aria-label')).toBe(
        'Like on Bluesky, 47 likes',
      );
    });

    it('keeps the vote pill in the discussion and leads the actions with the bare count of a voted post', () => {
      const element = discussionMeta({
        social: null,
        score: 5,
        myVote: 1,
        likeCount: 9,
        commentCount: null,
      });
      expect(element.querySelector('.discussion app-vote-pill')).not.toBeNull();
      expect(element.querySelector('.discussion app-entry-like')).toBeNull();
      const like = element.querySelector('app-entry-actions')!.firstElementChild as HTMLElement;
      expect(like.tagName).toBe('APP-ENTRY-LIKE');
      expect(like.querySelector('button')).toBeNull();
      expect(like.querySelector('.likes .count')?.textContent).toBe('9');
    });

    it('renders no discussion span for an entry with only a like', () => {
      const element = discussionMeta({
        score: null,
        commentCount: null,
        likeCount: 4,
        social: null,
        myVote: null,
      });
      expect(element.querySelector('.discussion')).toBeNull();
      expect(element.querySelector('app-entry-actions app-entry-like')).not.toBeNull();
    });

    it('shows the opened thread’s total over the stored count (#2433)', () => {
      const counted = anEntry({ score: null, commentCount: 3 });
      const fixture = mount([], counted);

      TestBed.inject(CommentTotals).record(counted, 7);
      fixture.detectChanges();

      const bubble = (fixture.nativeElement as HTMLElement).querySelector('.comments');
      expect(bubble?.getAttribute('aria-label')).toBe('7 comments');
    });

    it('shows a zero score', () => {
      expect(discussionMeta({ score: 0 }).querySelector('app-vote-pill')).not.toBeNull();
    });
  });

  it('renders the tag pills beside the actions', () => {
    const element = mount([tag(1, 'Tech')]).nativeElement as HTMLElement;
    expect(element.textContent).toContain('Tech');
    expect(element.querySelectorAll('app-entry-actions button').length).toBe(4);
  });

  describe('label pills (#2581)', () => {
    const favorite = anEntry({ isFavorite: true, labels: [{ id: 3, name: 'Work' }] });

    it('shows the labels of a favorite', () => {
      const element = mount([], favorite).nativeElement as HTMLElement;
      expect(element.querySelector('a.pill.label')?.textContent).toContain('Work');
    });

    it('shows none when its host turns them off', () => {
      const fixture = mount([], favorite);
      fixture.componentRef.setInput('labelsShown', false);
      fixture.detectChanges();

      expect((fixture.nativeElement as HTMLElement).querySelector('a.pill.label')).toBeNull();
    });
  });

  it('still renders the actions when the entry has no tags', () => {
    const element = mount([]).nativeElement as HTMLElement;
    expect(element.querySelector('.pill')).toBeNull();
    expect(element.querySelectorAll('app-entry-actions button').length).toBe(4);
  });

  it('sends each action to the entry action handler', () => {
    const fixture = mount([]);
    const favorite = jest.fn();
    const keep = jest.fn();
    const read = jest.fn();
    entryActions.favorite.mockImplementation(favorite);
    entryActions.keep.mockImplementation(keep);
    entryActions.toggleRead.mockImplementation(read);

    const buttons = fixture.nativeElement.querySelectorAll('app-entry-actions button');
    (buttons[1] as HTMLElement).click();
    (buttons[2] as HTMLElement).click();
    (buttons[3] as HTMLElement).click();

    expect(favorite).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
    expect(keep).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
    expect(read).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
  });

  describe('Short pill', () => {
    const shortPill = (testEntry: EntryDto, imageShown?: boolean) =>
      (mount([], testEntry, imageShown).nativeElement as HTMLElement).querySelector('.pill.short');

    it('marks a Short whose card shows no image', () => {
      expect(shortPill(anEntry({ shortPlayerUrl: SHORT_PLAYER_URL }), false)).not.toBeNull();
    });

    it('takes a card to show no image unless told otherwise', () => {
      expect(shortPill(anEntry({ shortPlayerUrl: SHORT_PLAYER_URL }))).not.toBeNull();
    });

    it('leaves a Short whose image is shown to the image badge', () => {
      expect(shortPill(anEntry({ shortPlayerUrl: SHORT_PLAYER_URL }), true)).toBeNull();
    });

    it('marks no other entry', () => {
      expect(shortPill(anEntry(), false)).toBeNull();
    });
  });

  it("shows the feed pill of the entry's subscription", () => {
    const testEntry = { ...anEntry(), subscriptionId: 7 };
    TestBed.configureTestingModule({
      imports: [EntryMetaComponent, provideTranslocoTesting()],
      providers: [
        provideAccountIdentity(),
        ...provideEntryActionsContext(),
        { provide: EntryActionHandler, useValue: entryActions },
        provideRouter([]),
      ],
    });
    TestBed.inject(SubscriptionRows).stored.set([
      makeSubscription({
        id: 7,
        title: 'Friends',
        customTitle: 'Friends',
        social: { network: 'mastodon', accountId: 1, kind: 'home' },
      }),
    ]);
    const fixture = TestBed.createComponent(EntryMetaComponent);
    fixture.componentRef.setInput('entry', testEntry);
    fixture.detectChanges();

    const pill = (fixture.nativeElement as HTMLElement).querySelector('.pill.feed');
    expect(pill?.textContent?.trim()).toBe('Friends');
  });
});
