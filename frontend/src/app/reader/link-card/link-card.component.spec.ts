import { TestBed } from '@angular/core/testing';
import { aLinkCard } from '../../../testing/entry-testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { LinkCardComponent, LinkCardLayout } from './link-card.component';

const card = aLinkCard({
  description: 'What it is about.',
  imageUrl: 'https://news.test/story.jpg',
});

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

  describe('open in reader', () => {
    function mountOpenable(openableInReader: boolean) {
      TestBed.configureTestingModule({
        imports: [LinkCardComponent, provideTranslocoTesting()],
      });
      const fixture = TestBed.createComponent(LinkCardComponent);
      fixture.componentRef.setInput('card', card);
      fixture.componentRef.setInput('layout', 'article');
      fixture.componentRef.setInput('openableInReader', openableInReader);
      const opened = jest.fn();
      fixture.componentInstance.openInReader.subscribe(opened);
      fixture.detectChanges();
      const element = fixture.nativeElement as HTMLElement;
      return {
        element,
        opened,
        button: element.querySelector<HTMLButtonElement>('button.open-in-reader'),
      };
    }

    it('has no button unless the card is openable', () => {
      expect(mountOpenable(false).button).toBeNull();
    });

    it('puts a labelled button beside the link, not inside it', () => {
      const { button } = mountOpenable(true);

      expect(button).not.toBeNull();
      expect(button!.getAttribute('aria-label')).toBe('Open in reader');
      expect(button!.querySelector('.open-in-reader-label')!.textContent).toBe('Open in reader');
      expect(button!.closest('a')).toBeNull();
    });

    it('emits once on click and lets no ancestor see the click', () => {
      const { element, button, opened } = mountOpenable(true);
      const ancestorClick = jest.fn();
      element.addEventListener('click', ancestorClick);

      button!.click();

      expect(opened).toHaveBeenCalledTimes(1);
      expect(ancestorClick).not.toHaveBeenCalled();
    });

    it.each(['Enter', ' '])('keeps the %j key inside the button', (key) => {
      const { element, button } = mountOpenable(true);
      const ancestorKeydown = jest.fn();
      element.addEventListener('keydown', ancestorKeydown);

      button!.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));

      expect(ancestorKeydown).not.toHaveBeenCalled();
    });
  });
});
