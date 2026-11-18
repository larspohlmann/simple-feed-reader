import { TestBed } from '@angular/core/testing';
import { aStory } from '../../../../testing/entry-testing';
import { STORY_ICON } from '../story';
import { StoryTitleComponent } from './story-title.component';

function mount(title: string): HTMLElement {
  TestBed.configureTestingModule({ imports: [StoryTitleComponent] });
  const fixture = TestBed.createComponent(StoryTitleComponent);
  fixture.componentRef.setInput('story', aStory({ title }));
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
