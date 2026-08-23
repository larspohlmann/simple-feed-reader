import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { FeedIntroComponent } from './feed-intro.component';

describe('FeedIntroComponent', () => {
  let fixture: ComponentFixture<FeedIntroComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [FeedIntroComponent, provideTranslocoTesting()],
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
      .filter((n) => n.nodeType === Node.TEXT_NODE)
      .map((n) => n.textContent ?? '')
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

  it('labels the homepage with its own address, not a generic word', () => {
    const link = render({ siteUrl: 'https://www.djmag.com/' }).querySelector('a');
    // Scheme and bare trailing slash dropped: identical on every feed, so they
    // cost width and say nothing. The full URL stays reachable on the title.
    expect(linkLabel(link)).toBe('www.djmag.com');
    expect(link?.getAttribute('title')).toBe('https://www.djmag.com/');
  });

  it('keeps a path in the label', () => {
    const link = render({ siteUrl: 'https://example.com/news' }).querySelector('a');
    expect(linkLabel(link)).toBe('example.com/news');
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

  it('reports no content when every value is null', () => {
    render({});
    expect(fixture.componentInstance.hasContent()).toBe(false);
  });

  it('hides a broken image instead of leaving a broken-image box', () => {
    const host = render({ imageUrl: 'https://example.com/dead.png' });
    host.querySelector('img')?.dispatchEvent(new Event('error'));
    fixture.detectChanges();
    expect(host.querySelector('img')).toBeNull();
  });
});
