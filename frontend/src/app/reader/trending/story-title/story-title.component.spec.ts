import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { aStory } from '../../../../testing/entry-testing';
import { provideStoryActions } from '../../../../testing/story-actions-testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { SelectionKind } from '../../query/query';
import { TrendingStoryDto } from '../trending.models';
import { STORY_ICON } from '../story';
import { StoryTitleComponent } from './story-title.component';

@Component({ template: '' })
class StoryListStubComponent {}

function mount(title: string, id = 12, synopsis: string | null = null): HTMLElement {
  return mountStory(aStory({ id, title, synopsis }));
}

function mountStory(
  story: TrendingStoryDto,
  kind: SelectionKind = 'trending',
  linked = true,
): HTMLElement {
  TestBed.configureTestingModule({
    imports: [StoryTitleComponent, provideTranslocoTesting()],
    providers: [
      provideRouter([{ path: 'trending/stories/:story', component: StoryListStubComponent }]),
      ...provideStoryActions(kind),
    ],
  });
  const fixture = TestBed.createComponent(StoryTitleComponent);
  fixture.componentRef.setInput('story', story);
  fixture.componentRef.setInput('linked', linked);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

afterEach(() => TestBed.resetTestingModule());

it("names the story's event as a heading", () => {
  const heading = mount('Harbour authority approves the tidal barrier').querySelector('h3.title');

  expect(heading?.textContent?.trim()).toBe('Harbour authority approves the tidal barrier');
});

it('leads with the Trending story glyph', () => {
  expect(mount('Any event').querySelector('app-icon')?.textContent?.trim()).toBe(STORY_ICON);
});

it('shows the whole title, with no tooltip standing in for a cut line', () => {
  const heading = mount('A very long event headline').querySelector('h3.title');

  expect(heading?.hasAttribute('title')).toBe(false);
});

it('shows the synopsis beneath the title', () => {
  const synopsis = mount(
    'Any event',
    12,
    'Dockers walked out at dawn. The port is shut.',
  ).querySelector('h3.title ~ p.synopsis');

  expect(synopsis?.textContent?.trim()).toBe('Dockers walked out at dawn. The port is shut.');
});

it('names the link by the title and describes it by the synopsis, unique per story', () => {
  const element = mount('Any event', 12, 'Dockers walked out.');
  const link = element.querySelector('a.story-link')!;
  const heading = element.querySelector('h3.title')!;
  const synopsis = element.querySelector('p.synopsis')!;

  expect(link.getAttribute('aria-labelledby')).toBe(heading.id);
  expect(link.getAttribute('aria-describedby')).toBe(synopsis.id);
  expect(synopsis.id).toBe('story-synopsis-12');
});

it('describes the link by nothing without a synopsis', () => {
  expect(mount('Any event').querySelector('a.story-link')!.hasAttribute('aria-describedby')).toBe(
    false,
  );
});

it('shows no synopsis element without one', () => {
  expect(mount('Any event').querySelector('.synopsis')).toBeNull();
});

it("links to the story's list and opens it when clicked", async () => {
  const link = mount('Any event', 12).querySelector<HTMLAnchorElement>('a.story-link')!;

  expect(link.getAttribute('href')).toBe('/trending/stories/12');

  link.click();
  await TestBed.inject(Router).navigated;
  await new Promise((resolve) => setTimeout(resolve));

  expect(TestBed.inject(Router).url).toBe('/trending/stories/12');
});

describe("the card's counts and actions (#2435)", () => {
  it('names how many feeds covered the story and how often it was shared', () => {
    const counts = mountStory(aStory({ outletCount: 7, shareCount: 2 })).querySelector(
      'app-story-source-count',
    )!;

    expect(counts.textContent).toContain('7 sources');
    expect(counts.textContent).toContain('2 shares');
  });

  it('names no shares on a story no post shares', () => {
    const counts = mountStory(aStory({ outletCount: 2, shareCount: 0 })).querySelector(
      'app-story-source-count',
    )!;

    expect(counts.textContent).not.toContain('share');
  });

  it('names the dates between the title and the synopsis', () => {
    const element = mountStory(aStory({ synopsis: 'Dockers walked out.' }));

    expect(element.querySelector('h3.title + app-story-dates + p.synopsis')).not.toBeNull();
  });

  it('offers the actions at the bottom of the card, after the synopsis', () => {
    const element = mountStory(aStory({ synopsis: 'Dockers walked out.' }));

    expect(element.lastElementChild!.classList.contains('story-foot')).toBe(true);
  });

  it('offers Follow and Hide beside the counts, outside the link', () => {
    const element = mountStory(aStory());

    expect(element.querySelectorAll('.story-foot .story-actions button')).toHaveLength(2);
    expect(element.querySelector('a.story-link button')).toBeNull();
  });

  it('offers no story actions off Trending', () => {
    const element = mountStory(aStory(), 'all');

    expect(element.querySelector('.story-actions')).toBeNull();
    expect(element.querySelector('app-story-source-count')).not.toBeNull();
  });
});

it('renders the counts at the foot, before the actions', () => {
  const element = mountStory(aStory());

  expect(
    element.querySelector('.story-foot > app-story-source-count + app-story-actions'),
  ).not.toBeNull();
});

it('with linked false renders no link, yet the title and the actions', () => {
  const element = mountStory(aStory({ title: 'Any event' }), 'trending', false);

  expect(element.querySelector('a')).toBeNull();
  expect(element.querySelector('h3.title')?.textContent?.trim()).toBe('Any event');
  expect(element.querySelectorAll('.story-foot .story-actions button')).toHaveLength(2);
});
