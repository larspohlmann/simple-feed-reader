import { TestBed } from '@angular/core/testing';
import { aLinkCard } from '../../../testing/entry-testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { LinkReaderNavigation } from '../shell/link-reader-navigation.service';
import {
  LinkCardComponent,
  LinkCardLayout,
  LinkCardReaderOpening,
  LinkCardSiteLine,
} from './link-card.component';

const card = aLinkCard({
  description: 'What it is about.',
  imageUrl: 'https://news.test/story.jpg',
});

function mount(layout: LinkCardLayout, siteLine: LinkCardSiteLine | null = null): HTMLElement {
  const fixture = TestBed.createComponent(LinkCardComponent);
  fixture.componentRef.setInput('card', card);
  fixture.componentRef.setInput('layout', layout);
  fixture.componentRef.setInput('siteLine', siteLine);
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

  it('puts the site last in the block layout when asked, the block’s type otherwise', () => {
    const element = mount('block', 'last');

    expect(element.classList).toContain('block');
    expect(partsOf(element)).toEqual(['IMG', 'STRONG', 'SPAN', 'SMALL']);
  });

  it('puts the site first in the article layout when asked', () => {
    expect(partsOf(mount('article', 'first'))).toEqual(['IMG', 'SMALL', 'STRONG', 'SPAN']);
  });

  describe('open in reader', () => {
    const navigation = { layer: jest.fn(), openAlone: jest.fn() };

    function mountOpenable(
      opensInReader: LinkCardReaderOpening | null,
      providers = [{ provide: LinkReaderNavigation, useValue: navigation }],
    ) {
      navigation.layer.mockReset();
      navigation.openAlone.mockReset();
      TestBed.configureTestingModule({
        imports: [LinkCardComponent, provideTranslocoTesting()],
        providers,
      });
      const fixture = TestBed.createComponent(LinkCardComponent);
      fixture.componentRef.setInput('card', card);
      fixture.componentRef.setInput('layout', 'article');
      fixture.componentRef.setInput('opensInReader', opensInReader);
      fixture.detectChanges();
      const element = fixture.nativeElement as HTMLElement;
      return {
        element,
        button: element.querySelector<HTMLButtonElement>('button.open-in-reader'),
      };
    }

    it('has no button unless the card opens in the reader', () => {
      expect(mountOpenable(null).button).toBeNull();
    });

    it('has no button outside the reader shell, where nothing can open it', () => {
      expect(mountOpenable('layer', []).button).toBeNull();
    });

    it('layers the card over the open post', () => {
      mountOpenable('layer').button!.click();

      expect(navigation.layer).toHaveBeenCalledWith(card);
      expect(navigation.openAlone).not.toHaveBeenCalled();
    });

    it('opens the card alone in place of the list', () => {
      mountOpenable('alone').button!.click();

      expect(navigation.openAlone).toHaveBeenCalledWith(card);
      expect(navigation.layer).not.toHaveBeenCalled();
    });

    it('puts a labelled button beside the link, not inside it', () => {
      const { button } = mountOpenable('layer');

      expect(button).not.toBeNull();
      expect(button!.getAttribute('aria-label')).toBe('Open in reader');
      expect(button!.querySelector('.open-in-reader-label')!.textContent).toBe('Open in reader');
      expect(button!.closest('a')).toBeNull();
    });

    it('opens once on click and lets no ancestor see the click', () => {
      const { element, button } = mountOpenable('layer');
      const ancestorClick = jest.fn();
      element.addEventListener('click', ancestorClick);

      button!.click();

      expect(navigation.layer).toHaveBeenCalledTimes(1);
      expect(ancestorClick).not.toHaveBeenCalled();
    });

    it.each(['Enter', ' '])('keeps the %j key inside the button', (key) => {
      const { element, button } = mountOpenable('layer');
      const ancestorKeydown = jest.fn();
      element.addEventListener('keydown', ancestorKeydown);

      button!.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));

      expect(ancestorKeydown).not.toHaveBeenCalled();
    });
  });
});
