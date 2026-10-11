import { TestBed } from '@angular/core/testing';
import { LinkCardDto } from './link-card';
import { LinkCardComponent, LinkCardLayout } from './link-card.component';

const card: LinkCardDto = {
  url: 'https://news.test/story',
  title: 'A shared story',
  description: 'What it is about.',
  imageUrl: 'https://news.test/story.jpg',
  siteName: 'news.test',
};

function mount(layout: LinkCardLayout): HTMLElement {
  const fixture = TestBed.createComponent(LinkCardComponent);
  fixture.componentRef.setInput('card', card);
  fixture.componentRef.setInput('layout', layout);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

function partsOf(element: HTMLElement): string[] {
  return [...element.querySelector('a')!.children].map((child) => child.tagName);
}

describe('LinkCardComponent', () => {
  it('reads like the card in a post body, its site last, in the article layout', () => {
    const element = mount('article');

    expect(element.classList).toContain('link-card');
    expect(element.classList).toContain('article');
    expect(partsOf(element)).toEqual(['IMG', 'STRONG', 'SPAN', 'SMALL']);
  });

  it('puts the site above the headline in the block layout', () => {
    const element = mount('block');

    expect(element.classList).toContain('block');
    expect(element.classList).not.toContain('article');
    expect(partsOf(element)).toEqual(['IMG', 'SMALL', 'STRONG', 'SPAN']);
  });
});
