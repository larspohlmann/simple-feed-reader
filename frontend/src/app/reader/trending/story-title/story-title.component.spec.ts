import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { aStory } from '../../../../testing/entry-testing';
import { STORY_ICON } from '../story';
import { StoryTitleComponent } from './story-title.component';

@Component({ template: '' })
class StoryListStubComponent {}

function mount(title: string, id = 12, synopsis: string | null = null): HTMLElement {
  TestBed.configureTestingModule({
    imports: [StoryTitleComponent],
    providers: [
      provideRouter([{ path: 'trending/stories/:story', component: StoryListStubComponent }]),
    ],
  });
  const fixture = TestBed.createComponent(StoryTitleComponent);
  fixture.componentRef.setInput('story', aStory({ id, title, synopsis }));
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
  ).querySelector('h3.title + p.synopsis');

  expect(synopsis?.textContent?.trim()).toBe('Dockers walked out at dawn. The port is shut.');
});

it('names the link by the title and describes it by the synopsis, unique per story', () => {
  const link = mount('Any event', 12, 'Dockers walked out.').querySelector('a.story-link')!;
  const heading = link.querySelector('h3.title')!;
  const synopsis = link.querySelector('p.synopsis')!;

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
  expect(link.querySelector('h3.title')).not.toBeNull();

  link.click();
  await TestBed.inject(Router).navigated;
  await new Promise((resolve) => setTimeout(resolve));

  expect(TestBed.inject(Router).url).toBe('/trending/stories/12');
});
