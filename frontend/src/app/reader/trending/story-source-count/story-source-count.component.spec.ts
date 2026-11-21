import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { aStory } from '../../../../testing/entry-testing';
import { TrendingStoryDto } from '../trending.models';
import { StorySourceCountComponent } from './story-source-count.component';

function mount(story: TrendingStoryDto): HTMLElement {
  TestBed.configureTestingModule({
    imports: [StorySourceCountComponent, provideTranslocoTesting()],
  });
  const fixture = TestBed.createComponent(StorySourceCountComponent);
  fixture.componentRef.setInput('story', story);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

const storyWith = (shareCount: number): TrendingStoryDto =>
  aStory({ outletCount: 7, viewed: false, shareCount });

const wordsOf = (element: HTMLElement): string[] =>
  [...element.querySelectorAll('.words')].map((count) => count.textContent!.trim());

it('names how many feeds covered the story and how often it was shared', () => {
  const element = mount(storyWith(2));

  expect(wordsOf(element)).toEqual(['7 sources', '2 shares']);
  expect(element.querySelectorAll('.separator').length).toBe(1);
});

it('names a single share in the singular', () => {
  expect(wordsOf(mount(storyWith(1)))).toEqual(['7 sources', '1 share']);
});

it('names no shares, and no separator, on a story no post shares', () => {
  const element = mount(storyWith(0));

  expect(wordsOf(element)).toEqual(['7 sources']);
  expect(element.querySelector('.separator')).toBeNull();
});

it('shows one icon per count, ahead of its words', () => {
  const icons = [...mount(storyWith(2)).querySelectorAll('app-icon')].map((icon) => ({
    icon: icon.textContent!.trim(),
    hidden: icon.getAttribute('aria-hidden'),
    before: icon.nextElementSibling!.classList.contains('words'),
  }));

  expect(icons).toEqual([
    { icon: 'trending_up', hidden: 'true', before: true },
    { icon: 'share', hidden: 'true', before: true },
  ]);
});

it('carries each count as a number hidden from assistive tech, its words as the tooltip', () => {
  const numbers = [...mount(storyWith(2)).querySelectorAll('.number')].map((number) => ({
    count: number.textContent!.trim(),
    title: number.getAttribute('title'),
    hidden: number.getAttribute('aria-hidden'),
  }));

  expect(numbers).toEqual([
    { count: '7', title: '7 sources', hidden: 'true' },
    { count: '2', title: '2 shares', hidden: 'true' },
  ]);
});
