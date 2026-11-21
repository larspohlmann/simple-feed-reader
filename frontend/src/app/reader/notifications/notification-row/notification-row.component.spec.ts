import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { By } from '@angular/platform-browser';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { AvatarComponent } from '../../../shared/avatar/avatar.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { NotificationGroupDto } from '../notification.models';
import { NotificationRowComponent } from './notification-row.component';

function actor(index: number) {
  return {
    handle: `@actor${index}@social.example`,
    displayName: `Actor ${index}`,
    avatarUrl: null,
    profileUrl: `https://social.example/@actor${index}`,
  };
}

function group(overrides: Partial<NotificationGroupDto> = {}): NotificationGroupDto {
  return {
    id: 1,
    kind: 'like',
    account: '@lars.bsky.social',
    actors: [actor(1)],
    actorCount: 1,
    excerpt: 'An excerpt',
    answeredExcerpt: null,
    threadTitle: null,
    threadPlace: null,
    threadBy: null,
    onComment: false,
    linkUrl: 'https://social.example/p/1',
    entryId: null,
    latestAt: '2026-11-16T10:00:00Z',
    seen: true,
    ...overrides,
  };
}

describe('NotificationRowComponent', () => {
  let fixture: ComponentFixture<NotificationRowComponent>;

  function render(source: NotificationGroupDto, showsAccount = false): HTMLElement {
    fixture = TestBed.createComponent(NotificationRowComponent);
    fixture.componentRef.setInput('group', source);
    fixture.componentRef.setInput('showsAccount', showsAccount);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [NotificationRowComponent, provideTranslocoTesting()],
      providers: [provideRouter([])],
    });
  });

  it('links out to the post in a new tab', () => {
    const link = render(group()).querySelector('a') as HTMLAnchorElement;
    expect(link.getAttribute('href')).toBe('https://social.example/p/1');
    expect(link.getAttribute('target')).toBe('_blank');
    expect(link.getAttribute('rel')).toBe('noopener noreferrer');
  });

  it('opens a resolved row in the reader, same tab', () => {
    const link = render(group({ entryId: 514 })).querySelector('a.row') as HTMLAnchorElement;
    expect(link.getAttribute('target')).toBeNull();
    expect(link.getAttribute('href')).toContain('entry=514');
  });

  it('keeps an unresolved row as an external link', () => {
    const link = render(group({ entryId: null })).querySelector('a.row') as HTMLAnchorElement;
    expect(link.getAttribute('href')).toBe('https://social.example/p/1');
    expect(link.getAttribute('target')).toBe('_blank');
  });

  it("draws the kind glyph filled in the kind's tone", () => {
    const element = render(group());
    const icon = fixture.debugElement.query(By.directive(IconComponent));
    expect(icon.componentInstance.name()).toBe('favorite');
    expect(icon.componentInstance.fill()).toBe(true);
    expect(element.querySelector('.kind')?.classList.contains('like')).toBe(true);
  });

  it('overlaps at most three avatars', () => {
    render(group({ actors: [1, 2, 3, 4].map(actor), actorCount: 4 }));
    const avatars = fixture.debugElement.queryAll(By.directive(AvatarComponent));
    expect(avatars).toHaveLength(3);
    avatars.forEach((avatar, index) => {
      expect(avatar.componentInstance.size()).toBe('md');
      expect(avatar.componentInstance.toneSeed()).toBe(actor(index + 1).handle);
    });
  });

  it('shows the excerpt only when there is one', () => {
    expect(render(group({ kind: 'reply' })).querySelector('.excerpt')?.textContent).toContain(
      'An excerpt',
    );
    expect(render(group({ excerpt: null })).querySelector('.excerpt')).toBeNull();
  });

  it('marks an unseen row with a dot', () => {
    const unseen = render(group({ seen: false }));
    expect(unseen.querySelector('.dot')).not.toBeNull();
    expect(unseen.querySelector('.sr-only')?.textContent).toContain('Unseen');
    expect(unseen.querySelector('.row')?.className).toBe('row');

    const seen = render(group({ seen: true }));
    expect(seen.querySelector('.dot')).toBeNull();
    expect(seen.querySelector('.sr-only')).toBeNull();
  });

  it('names the receiving account only when asked', () => {
    expect(render(group(), true).querySelector('.account')?.textContent).toContain(
      '@lars.bsky.social',
    );
    expect(render(group()).querySelector('.account')).toBeNull();
  });

  it('renders the line without stray spaces', () => {
    const actors = [1, 2, 3].map(actor);
    const element = render(group({ actors, actorCount: 7 }));
    expect(element.querySelector('.line')?.textContent).toBe(
      'Actor 1, Actor 2 and 5 others liked your post',
    );
  });

  describe('context', () => {
    const replyWithContext = group({
      kind: 'reply',
      excerpt: 'Their reply',
      answeredExcerpt: 'My post',
      threadTitle: 'Self-hosting',
      threadPlace: 'c/selfhosted',
    });

    it('renders reply text, quote line and place line in that order', () => {
      const element = render(replyWithContext);
      const excerpt = element.querySelector('.excerpt') as Element;
      const quote = element.querySelector('.quote') as Element;
      const place = element.querySelector('.place') as Element;
      expect(
        excerpt.compareDocumentPosition(quote) & Node.DOCUMENT_POSITION_FOLLOWING,
      ).toBeTruthy();
      expect(quote.compareDocumentPosition(place) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
      expect(place.textContent).toContain('Self-hosting');
      expect(place.textContent).toContain('· c/selfhosted');
    });

    it('prefixes the quote with a bold You:', () => {
      const element = render(replyWithContext);
      expect(element.querySelector('.quote .you')?.textContent).toBe('You:');
      expect(element.querySelector('.quote')?.textContent).toContain('My post');
    });

    it("draws a like's excerpt as a quote, not as reply text", () => {
      const element = render(group({ kind: 'like', excerpt: 'My liked post' }));
      expect(element.querySelector('.excerpt')).toBeNull();
      expect(element.querySelector('.quote')?.textContent).toContain('My liked post');
    });

    it('says "in a thread by Zoe:" before a Bluesky thread title', () => {
      const element = render(group({ threadBy: 'Zoe', threadTitle: 'A thread' }));
      const place = element.querySelector('.place') as Element;
      expect(place.textContent).toContain('in a thread by Zoe:');
      expect(place.textContent?.indexOf('Zoe')).toBeLessThan(
        place.textContent?.indexOf('A thread') ?? 0,
      );
    });

    it('draws a reply to your comment with the reply glyph and the comment wording', () => {
      const element = render(group({ kind: 'reply', onComment: true }));
      const icon = fixture.debugElement.query(By.directive(IconComponent));
      expect(icon.componentInstance.name()).toBe('chat_bubble');
      expect(element.querySelector('.kind')?.classList.contains('reply')).toBe(true);
      expect(element.textContent).toContain('replied to your comment');
    });

    it('shows no quote or place line without context', () => {
      const element = render(group({ kind: 'reply' }));
      expect(element.querySelector('.quote')).toBeNull();
      expect(element.querySelector('.place')).toBeNull();
    });
  });
});
