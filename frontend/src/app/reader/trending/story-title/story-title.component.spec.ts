import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { aStory } from '../../../../testing/entry-testing';
import { STORY_ICON } from '../story';
import { StoryTitleComponent } from './story-title.component';

@Component({ template: '' })
class StoryListStubComponent {}

function mount(title: string, id = 12): HTMLElement {
  TestBed.configureTestingModule({
    imports: [StoryTitleComponent],
    providers: [
      provideRouter([{ path: 'trending/stories/:story', component: StoryListStubComponent }]),
    ],
  });
  const fixture = TestBed.createComponent(StoryTitleComponent);
  fixture.componentRef.setInput('story', aStory({ id, title }));
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

it('carries the full title as a tooltip for when the line is cut short', () => {
  const heading = mount('A very long event headline').querySelector('h3.title');

  expect(heading?.getAttribute('title')).toBe('A very long event headline');
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
