import { signal } from '@angular/core';
import { of } from 'rxjs';
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { aStory } from '../../../../testing/entry-testing';
import { FollowedStoriesStore } from '../../state/followed-stories.store';
import { TrendingApi } from '../trending-api';
import { TrendingStoryDto } from '../trending.models';
import { STORY_ACTIONS, StoryActions } from './story-actions';
import { StoryActionsComponent } from './story-actions.component';

describe('StoryActionsComponent (#2128)', () => {
  const follow = jest.fn();
  const hide = jest.fn();
  const isFollowed = (story: TrendingStoryDto) => story.followed;
  const provided = signal<StoryActions | null>({ follow, hide, isFollowed });

  const mount = (story: TrendingStoryDto) => {
    TestBed.configureTestingModule({
      imports: [StoryActionsComponent, provideTranslocoTesting()],
      providers: [{ provide: STORY_ACTIONS, useValue: provided }],
    });
    const fixture = TestBed.createComponent(StoryActionsComponent);
    fixture.componentRef.setInput('story', story);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  };
  const card = (followed = false) => aStory({ id: 12, followed });
  const buttons = (element: HTMLElement) => [...element.querySelectorAll('button')];
  const labels = (element: HTMLElement) =>
    buttons(element).map((button) => button.getAttribute('aria-label'));
  const glyph = (button: HTMLButtonElement) => button.querySelector('app-icon')!;

  beforeEach(() => {
    follow.mockReset();
    hide.mockReset();
    provided.set({ follow, hide, isFollowed });
  });

  it('groups Follow before Hide under the story', () => {
    const element = mount(card());

    expect(element.querySelector('.story-actions')!.getAttribute('aria-label')).toBe('Story');
    expect(labels(element)).toEqual(['Follow story', 'Hide story']);
  });

  it('offers to follow an unfollowed story with an empty bell, not pressed', () => {
    const [toggle] = buttons(mount(card(false)));

    expect(toggle.getAttribute('aria-pressed')).toBe('false');
    expect(toggle.getAttribute('title')).toBe(
      'Follow this story — what joins it shows in the sidebar',
    );
    expect(glyph(toggle).textContent!.trim()).toBe('notification_add');
    expect(glyph(toggle).classList.contains('filled')).toBe(false);
  });

  it('shows a followed story pressed, with a filled ringing bell that unfollows', () => {
    const [toggle] = buttons(mount(card(true)));

    expect(toggle.getAttribute('aria-label')).toBe('Unfollow story');
    expect(toggle.getAttribute('aria-pressed')).toBe('true');
    expect(toggle.getAttribute('title')).toBe('Stop following this story');
    expect(glyph(toggle).textContent!.trim()).toBe('notifications_active');
    expect(glyph(toggle).classList.contains('filled')).toBe(true);
  });

  it('unfollowing from the sidebar unfollows the card', () => {
    TestBed.configureTestingModule({
      providers: [
        {
          provide: TrendingApi,
          useValue: {
            followed: () => of({ stories: [], nextBuildDueAt: null }),
            unfollow: () => of(undefined),
          },
        },
      ],
    });
    const followedStories = () => TestBed.inject(FollowedStoriesStore);
    provided.set({ follow, hide, isFollowed: (story) => followedStories().isFollowed(story) });
    const element = mount(card(true));

    followedStories().unfollow(12);
    TestBed.tick();

    const [toggle] = buttons(element);
    expect(toggle.getAttribute('aria-pressed')).toBe('false');
    expect(glyph(toggle).textContent!.trim()).toBe('notification_add');
  });

  it('leaves Hide unmarked, since it is no toggle', () => {
    const hideButton = buttons(mount(card())).at(-1)!;

    expect(hideButton.hasAttribute('aria-pressed')).toBe(false);
    expect(hideButton.getAttribute('title')).toBe(
      'Hide this story from Trending — it stays hidden as it grows',
    );
  });

  it('runs each action on its card without the click reaching the card', () => {
    const story = card();
    const element = mount(story);
    const cardClicks = jest.fn();
    element.addEventListener('click', cardClicks);

    buttons(element).forEach((button) => button.click());

    expect(follow).toHaveBeenCalledWith(story);
    expect(hide).toHaveBeenCalledWith(story);
    expect(cardClicks).not.toHaveBeenCalled();
  });

  it('offers nothing while the list provides no story actions', () => {
    provided.set(null);

    expect(mount(card()).querySelector('.story-actions')).toBeNull();
  });

  it('offers nothing on a card rendered outside the reader shell', () => {
    TestBed.configureTestingModule({
      imports: [StoryActionsComponent, provideTranslocoTesting()],
    });
    const fixture = TestBed.createComponent(StoryActionsComponent);
    fixture.componentRef.setInput('story', card());
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).querySelector('.story-actions')).toBeNull();
  });
});
