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
  [...element.querySelectorAll('.sources')].map((count) => count.textContent!.trim());

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

it('carries a compact icon-and-number form of each count, its words as the tooltip', () => {
  const compact = [...mount(storyWith(2)).querySelectorAll('.compact')].map((glyph) => ({
    icon: glyph.querySelector('app-icon')!.textContent!.trim(),
    count: glyph.textContent!.replace(glyph.querySelector('app-icon')!.textContent!, '').trim(),
    title: glyph.getAttribute('title'),
    hidden: glyph.getAttribute('aria-hidden'),
  }));

  expect(compact).toEqual([
    { icon: 'trending_up', count: '7', title: '7 sources', hidden: 'true' },
    { icon: 'share', count: '2', title: '2 shares', hidden: 'true' },
  ]);
});

it('leads each full-word count with its icon, the story glyph and the share glyph', () => {
  const leads = [...mount(storyWith(2)).querySelectorAll('.lead')].map((icon) => ({
    icon: icon.textContent!.trim(),
    hidden: icon.getAttribute('aria-hidden'),
    before: icon.nextElementSibling!.classList.contains('sources'),
  }));

  expect(leads).toEqual([
    { icon: 'trending_up', hidden: 'true', before: true },
    { icon: 'share', hidden: 'true', before: true },
  ]);
});
