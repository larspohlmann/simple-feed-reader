import { ComponentFixture, TestBed } from '@angular/core/testing';
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
    linkUrl: 'https://social.example/p/1',
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
    });
  });

  it('links out to the post in a new tab', () => {
    const link = render(group()).querySelector('a') as HTMLAnchorElement;
    expect(link.getAttribute('href')).toBe('https://social.example/p/1');
    expect(link.getAttribute('target')).toBe('_blank');
    expect(link.getAttribute('rel')).toBe('noopener noreferrer');
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
    expect(render(group()).querySelector('.excerpt')?.textContent).toContain('An excerpt');
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
});
