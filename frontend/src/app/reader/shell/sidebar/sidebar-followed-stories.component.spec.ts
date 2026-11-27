import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Location } from '@angular/common';
import { Router, provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { LayoutService } from '../../layout.service';
import { FollowedStoriesStore } from '../../state/followed-stories.store';
import { FollowedStoryDto } from '../../trending/trending.models';
import { storyRootState } from '../../trending/story';
import { SidebarFollowedStoriesComponent } from './sidebar-followed-stories.component';
import { SidebarRowActions } from './sidebar-row-actions.service';

const story = (id: number, over: Partial<FollowedStoryDto> = {}): FollowedStoryDto => ({
  id,
  headline: `Headline ${id}`,
  newCount: 0,
  quiet: false,
  lastJoinedAt: '2026-11-16T10:00:00+00:00',
  ...over,
});

describe('SidebarFollowedStoriesComponent (#2128)', () => {
  const unfollow = jest.fn();
  const menuFor = signal<string | null>(null);

  function mount(
    stories: FollowedStoryDto[],
    activeStoryId: number | null = null,
  ): ComponentFixture<SidebarFollowedStoriesComponent> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [SidebarFollowedStoriesComponent, provideTranslocoTesting()],
      providers: [
        provideRouter([{ path: '**', children: [] }]),
        { provide: FollowedStoriesStore, useValue: { unfollow } },
        {
          provide: SidebarRowActions,
          useValue: { menuFor, toggleMenu: jest.fn(), closeMenu: jest.fn() },
        },
        { provide: LayoutService, useValue: { isCoarse: signal(false) } },
      ],
    });
    const fixture = TestBed.createComponent(SidebarFollowedStoriesComponent);
    fixture.componentRef.setInput('stories', stories);
    fixture.componentRef.setInput('activeStoryId', activeStoryId);
    fixture.detectChanges();
    return fixture;
  }

  const root = (fixture: ComponentFixture<unknown>): HTMLElement => fixture.nativeElement;
  const expand = (fixture: ComponentFixture<unknown>): void => {
    root(fixture).querySelector<HTMLButtonElement>('.chevzone')?.click();
    fixture.detectChanges();
  };
  const rowTexts = (fixture: ComponentFixture<unknown>): string[] =>
    Array.from(root(fixture).querySelectorAll('.followed-row .name')).map(
      (name) => name.textContent?.trim() ?? '',
    );

  beforeEach(() => {
    jest.clearAllMocks();
    menuFor.set(null);
  });

  it('renders no card without stories', () => {
    const fixture = mount([]);

    expect(root(fixture).querySelector('.card')).toBeNull();
  });

  it('starts collapsed, with the head label and the summed new count', () => {
    const fixture = mount([story(1, { newCount: 2 }), story(2, { newCount: 3 }), story(3)]);

    expect(root(fixture).querySelector('.label')?.textContent).toContain('Followed stories');
    expect(root(fixture).querySelector('.section-count')?.textContent?.trim()).toBe('5');
    expect(root(fixture).querySelector('.followed-row')).toBeNull();
    expect(root(fixture).querySelector('.chevzone')?.getAttribute('aria-expanded')).toBe('false');
  });

  it('shows no head count when nothing is new', () => {
    const fixture = mount([story(1)]);

    expect(root(fixture).querySelector('.section-count')).toBeNull();
  });

  it('opens on the chevron and closes again', () => {
    const fixture = mount([story(1, { newCount: 2 })]);

    expand(fixture);

    expect(root(fixture).querySelectorAll('.followed-row')).toHaveLength(1);
    expand(fixture);
    expect(root(fixture).querySelector('.followed-row')).toBeNull();
  });

  it('opens on the head label too', () => {
    const fixture = mount([story(1)]);

    root(fixture).querySelector<HTMLButtonElement>('.followed-toggle')?.click();
    fixture.detectChanges();

    expect(root(fixture).querySelectorAll('.followed-row')).toHaveLength(1);
  });

  it('leads the head with the followed-story glyph', () => {
    const fixture = mount([story(1)]);

    expect(
      root(fixture).querySelector('.section-head .favicon-slot app-icon')?.textContent?.trim(),
    ).toBe('notifications_active');
  });

  it('hangs its story rows on the shared sidebar sublist', () => {
    const fixture = mount([story(1), story(2)]);
    expand(fixture);

    expect(root(fixture).querySelectorAll('.sublist > .followed-row')).toHaveLength(2);
  });

  it('lists the rows in the order given, each linking to its story', () => {
    const fixture = mount([story(7), story(4), story(9)]);
    expand(fixture);

    expect(rowTexts(fixture)).toEqual(['Headline 7', 'Headline 4', 'Headline 9']);
    const links = Array.from(root(fixture).querySelectorAll('.followed-row .nav')).map((link) =>
      link.getAttribute('href'),
    );
    expect(links).toEqual(['/trending/stories/7', '/trending/stories/4', '/trending/stories/9']);
  });

  it('opens the story over the current list but closes the open article (#2479)', async () => {
    const fixture = mount([story(7)]);
    await TestBed.inject(Router).navigateByUrl('/?tag=56&entry=12-an-article&link=abc');
    expand(fixture);

    const link = root(fixture).querySelector('.followed-row .nav');
    expect(link?.getAttribute('href')).toBe('/trending/stories/7?tag=56');
  });

  it('opens the story as the root, as a feed is (#2518)', async () => {
    const fixture = mount([story(7)]);
    const router = TestBed.inject(Router);
    await router.navigateByUrl('/?tag=56');
    expand(fixture);

    root(fixture).querySelector<HTMLAnchorElement>('.followed-row .nav')!.click();
    await fixture.whenStable();

    expect(router.url).toBe('/trending/stories/7?tag=56');
    expect(TestBed.inject(Location).getState()).toEqual(expect.objectContaining(storyRootState(7)));
  });

  it('shows the count on a row that has new members, and none on one that has not', () => {
    const fixture = mount([story(1, { newCount: 4 }), story(2)]);
    expand(fixture);

    const counts = Array.from(root(fixture).querySelectorAll('.followed-row')).map((followed) =>
      followed.querySelector('.count')?.textContent?.trim(),
    );
    expect(counts).toEqual(['4', undefined]);
  });

  it('marks a quiet story with its hint', () => {
    const fixture = mount([story(1, { quiet: true }), story(2)]);
    expand(fixture);

    const badges = root(fixture).querySelectorAll('.quiet-badge');
    expect(badges).toHaveLength(1);
    expect(badges[0].textContent?.trim()).toBe('quiet');
    expect(badges[0].getAttribute('title')).toBe('Nothing new for two days');
  });

  it('falls back to a generic label for a story without a headline', () => {
    const fixture = mount([story(1, { headline: null })]);
    expand(fixture);

    expect(rowTexts(fixture)).toEqual(['Followed story']);
  });

  it('marks only the active story', () => {
    const fixture = mount([story(1), story(2)], 2);
    expand(fixture);

    const active = Array.from(root(fixture).querySelectorAll('.followed-row')).map((followed) =>
      followed.classList.contains('active'),
    );
    expect(active).toEqual([false, true]);
  });

  it('unfollows from the row menu', () => {
    const fixture = mount([story(1), story(2)]);
    expand(fixture);
    menuFor.set('followed-story-2');
    fixture.detectChanges();

    const item = root(fixture).querySelector<HTMLButtonElement>('[role="menuitem"]');
    expect(item?.textContent?.trim()).toBe('Unfollow story');
    item?.click();

    expect(unfollow).toHaveBeenCalledWith(2);
  });
});
