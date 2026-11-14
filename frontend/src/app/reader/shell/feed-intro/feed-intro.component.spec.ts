import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ImageProxyService } from '../../../shared/proxied-image/image-proxy.service';
import { neverRecoveringImageProxy } from '../../../../testing/image-proxy-testing';
import {
  installMockResizeObserver,
  MockResizeObserver,
} from '../../../../testing/resize-observer-testing';
import { FeedIntroComponent } from './feed-intro.component';

describe('FeedIntroComponent', () => {
  let fixture: ComponentFixture<FeedIntroComponent>;
  let imageProxy: ReturnType<typeof neverRecoveringImageProxy>;

  beforeEach(async () => {
    imageProxy = neverRecoveringImageProxy();
    await TestBed.configureTestingModule({
      imports: [FeedIntroComponent, provideTranslocoTesting()],
      providers: [{ provide: ImageProxyService, useValue: imageProxy }],
    }).compileComponents();
    fixture = TestBed.createComponent(FeedIntroComponent);
  });

  function render(values: {
    title?: string;
    description?: string;
    imageUrl?: string;
    faviconUrl?: string;
    siteUrl?: string;
  }): HTMLElement {
    fixture.componentRef.setInput('title', values.title ?? null);
    fixture.componentRef.setInput('description', values.description ?? null);
    fixture.componentRef.setInput('imageUrl', values.imageUrl ?? null);
    fixture.componentRef.setInput('faviconUrl', values.faviconUrl ?? null);
    fixture.componentRef.setInput('siteUrl', values.siteUrl ?? null);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  // The link's own words, without the icon's ligature text, which lives in a
  // child element and would otherwise land in textContent.
  function linkLabel(link: Element | null): string {
    return [...(link?.childNodes ?? [])]
      .filter((node) => node.nodeType === Node.TEXT_NODE)
      .map((node) => node.textContent ?? '')
      .join('')
      .trim();
  }

  it('renders the description', () => {
    expect(render({ description: 'A feed about things.' }).textContent).toContain(
      'A feed about things.',
    );
  });

  it('renders the title above the description', () => {
    const host = render({ title: 'The Quietus', description: 'Culture countered.' });
    const title = host.querySelector('.title');
    expect(title?.textContent?.trim()).toBe('The Quietus');
    // Order matters: the name heads the block, the blurb explains it.
    expect(title?.compareDocumentPosition(host.querySelector('.text')!)).toBe(
      Node.DOCUMENT_POSITION_FOLLOWING,
    );
  });

  it('renders the image', () => {
    const img = render({ imageUrl: 'https://example.com/logo.png' }).querySelector('img');
    expect(img?.getAttribute('src')).toBe('https://example.com/logo.png');
  });

  it('renders the homepage link in a new tab, without leaking the referrer', () => {
    const link = render({ siteUrl: 'https://example.com/' }).querySelector('a');
    expect(link?.getAttribute('href')).toBe('https://example.com/');
    expect(link?.getAttribute('target')).toBe('_blank');
    expect(link?.getAttribute('rel')).toBe('noopener noreferrer');
  });

  it('labels the homepage link Open original and keeps the full address on its title', () => {
    const link = render({ siteUrl: 'https://www.djmag.com/news' }).querySelector('a');
    expect(linkLabel(link)).toBe('Open original');
    expect(link?.getAttribute('href')).toBe('https://www.djmag.com/news');
    expect(link?.getAttribute('title')).toBe('https://www.djmag.com/news');
  });

  it('omits each part whose value is null', () => {
    const host = render({ description: 'Only text.' });
    expect(host.querySelector('img')).toBeNull();
    expect(host.querySelector('a')).toBeNull();
  });

  it('omits the description when it is null', () => {
    expect(
      render({ imageUrl: 'https://example.com/logo.png' }).querySelector('.description'),
    ).toBeNull();
  });

  it('always draws the labelled divider, even for a feed that says nothing', () => {
    // It is what separates the block from the first entry; a feed with a logo
    // and nothing else must still get one.
    const host = render({ imageUrl: 'https://example.com/logo.png' });
    expect(host.querySelector('.rule')).not.toBeNull();
    expect(host.querySelector('.rule__label')?.textContent?.trim()).toBe('Posts');
  });

  it('keeps the blurb and the homepage link in one flow', () => {
    // The link trails the last line of a wrapped blurb rather than claiming a
    // row of its own. A link outside .lead means it has taken one again.
    const host = render({ description: 'A feed about things.', siteUrl: 'https://example.com/' });
    expect(host.querySelector('.lead .description')).not.toBeNull();
    expect(host.querySelector('.lead a.homepage')).not.toBeNull();
  });

  it('falls back to the favicon when the feed publishes no image of its own', () => {
    // Through the shared component, not a bare <img>: it owns the backdrop chip
    // that keeps a dark-ink favicon visible on the dark surface.
    const host = render({ faviconUrl: 'https://example.com/favicon.ico' });
    expect(host.querySelector('app-favicon')).not.toBeNull();
    expect(host.querySelector('img')?.getAttribute('src')).toBe('https://example.com/favicon.ico');
  });

  it('degrades a dead feed image to the favicon rather than to nothing', () => {
    const host = render({
      imageUrl: 'https://example.com/dead.png',
      faviconUrl: 'https://example.com/favicon.ico',
    });
    host.querySelector('img')?.dispatchEvent(new Event('error'));
    fixture.detectChanges();
    expect(host.querySelector('app-favicon')).not.toBeNull();
  });

  it("prefers the feed's own image over the favicon", () => {
    const img = render({
      imageUrl: 'https://example.com/logo.png',
      faviconUrl: 'https://example.com/favicon.ico',
    }).querySelector('img');
    expect(img?.getAttribute('src')).toBe('https://example.com/logo.png');
  });

  it('hides a broken image instead of leaving a broken-image box, without a proxy retry', () => {
    const host = render({ imageUrl: 'https://example.com/dead.png' });
    host.querySelector('img')?.dispatchEvent(new Event('error'));
    fixture.detectChanges();
    expect(host.querySelector('img')).toBeNull();
    expect(imageProxy.attempts).toEqual([]);
  });

  describe('a long description', () => {
    afterEach(() => jest.restoreAllMocks());

    function wrapOnto(lines: number): void {
      jest
        .spyOn(HTMLElement.prototype, 'getClientRects')
        .mockReturnValue(Array.from({ length: lines }) as unknown as DOMRectList);
    }

    function renderWrapped(lines: number, description = 'A very long blurb.'): HTMLElement {
      wrapOnto(lines);
      const host = render({ description, siteUrl: 'https://example.com/' });
      fixture.detectChanges();
      return host;
    }

    function toggle(host: HTMLElement): HTMLButtonElement {
      return host.querySelector<HTMLButtonElement>('button.toggle')!;
    }

    it('leaves a description of two lines whole, without a toggle', () => {
      const host = renderWrapped(2);
      expect(host.querySelector('.description.clamped')).toBeNull();
      expect(host.querySelector('button.toggle')).toBeNull();
    });

    it('clamps a description of three lines behind a Show more toggle', () => {
      const host = renderWrapped(3);
      expect(host.querySelector('.description.clamped')).not.toBeNull();
      expect(toggle(host).textContent?.trim()).toBe('Show more');
      expect(toggle(host).getAttribute('aria-expanded')).toBe('false');
    });

    it('keeps the homepage link outside the clamped text', () => {
      const host = renderWrapped(3);
      expect(host.querySelector('.description a.homepage')).toBeNull();
      expect(host.querySelector('.text > a.homepage')).not.toBeNull();
    });

    it('shows the rest on Show more and clamps again on Show less', () => {
      const host = renderWrapped(3);
      toggle(host).click();
      fixture.detectChanges();
      expect(host.querySelector('.description.clamped')).toBeNull();
      expect(toggle(host).textContent?.trim()).toBe('Show less');
      expect(toggle(host).getAttribute('aria-expanded')).toBe('true');

      toggle(host).click();
      fixture.detectChanges();
      expect(host.querySelector('.description.clamped')).not.toBeNull();
    });

    it('clamps again when another feed takes the place of an expanded one', () => {
      const host = renderWrapped(3);
      toggle(host).click();
      fixture.detectChanges();

      fixture.componentRef.setInput('description', 'Another very long blurb.');
      fixture.detectChanges();
      fixture.detectChanges();
      expect(host.querySelector('.description.clamped')).not.toBeNull();
    });

    it('drops the toggle once the text has room for the whole description', () => {
      installMockResizeObserver();
      const host = renderWrapped(3);
      wrapOnto(2);
      MockResizeObserver.instances.at(-1)?.fire();
      fixture.detectChanges();
      expect(host.querySelector('button.toggle')).toBeNull();
    });
  });
});
