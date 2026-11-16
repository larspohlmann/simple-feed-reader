import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { anEntry, aStory } from '../../../../testing/entry-testing';
import { EntryDto } from '../../models';
import { SelectionKind } from '../../query/query';
import { ReaderRouteState } from '../../shell/reader-route-state.service';
import { TrendingFollowAction } from '../trending-follow-action';
import { TrendingHideAction } from '../trending-hide-action';
import { StoryActionsComponent } from './story-actions.component';

describe('StoryActionsComponent (#2128)', () => {
  const follow = { run: jest.fn() };
  const hide = { run: jest.fn() };
  const selection = signal<{ kind: SelectionKind }>({ kind: 'trending' });

  const mount = (entry: EntryDto) => {
    TestBed.configureTestingModule({
      imports: [StoryActionsComponent, provideTranslocoTesting()],
      providers: [
        { provide: ReaderRouteState, useValue: { selection } },
        { provide: TrendingFollowAction, useValue: follow },
        { provide: TrendingHideAction, useValue: hide },
      ],
    });
    const fixture = TestBed.createComponent(StoryActionsComponent);
    fixture.componentRef.setInput('entry', entry);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  };
  const card = (followed = false) => anEntry({ id: 7, story: aStory({ id: 12, followed }) });
  const buttons = (element: HTMLElement) => [...element.querySelectorAll('button')];
  const labels = (element: HTMLElement) =>
    buttons(element).map((button) => button.getAttribute('aria-label'));
  const glyph = (button: HTMLButtonElement) => button.querySelector('app-icon')!;

  beforeEach(() => {
    follow.run.mockReset();
    hide.run.mockReset();
    selection.set({ kind: 'trending' });
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

  it('leaves Hide unmarked, since it is no toggle', () => {
    const hideButton = buttons(mount(card())).at(-1)!;

    expect(hideButton.hasAttribute('aria-pressed')).toBe(false);
    expect(hideButton.getAttribute('title')).toBe(
      'Hide this story from Trending — it stays hidden as it grows',
    );
  });

  it('runs each action on its card without the click reaching the card', () => {
    const entry = card();
    const element = mount(entry);
    const cardClicks = jest.fn();
    element.addEventListener('click', cardClicks);

    buttons(element).forEach((button) => button.click());

    expect(follow.run).toHaveBeenCalledWith(entry);
    expect(hide.run).toHaveBeenCalledWith(entry);
    expect(cardClicks).not.toHaveBeenCalled();
  });

  it('offers nothing outside Trending', () => {
    selection.set({ kind: 'all' });

    expect(mount(card()).querySelector('.story-actions')).toBeNull();
  });

  it('offers nothing on a card rendered outside the reader shell', () => {
    TestBed.configureTestingModule({
      imports: [StoryActionsComponent, provideTranslocoTesting()],
    });
    const fixture = TestBed.createComponent(StoryActionsComponent);
    fixture.componentRef.setInput('entry', card());
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).querySelector('.story-actions')).toBeNull();
  });

  it('offers nothing for a row with no story', () => {
    expect(mount(anEntry({ id: 7 })).querySelector('.story-actions')).toBeNull();
  });
});
