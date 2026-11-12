import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { FeedDirectoryFeed } from '../feed-directory-rows';
import { FeedDirectoryFeedsPopoverComponent } from './feed-directory-feeds-popover.component';

const FEEDS: FeedDirectoryFeed[] = [
  {
    url: 'https://www.spiegel.de/kultur/index.rss',
    category: 'Culture',
    podcast: false,
    subscribed: true,
  },
  {
    url: 'https://www.spiegel.de/ausland/index.rss',
    category: 'World',
    podcast: false,
    subscribed: false,
  },
  {
    url: 'https://www.spiegel.de/kultur/kino/index.rss',
    category: 'Culture',
    podcast: false,
    subscribed: false,
  },
];

describe('FeedDirectoryFeedsPopoverComponent', () => {
  let fixture: ComponentFixture<FeedDirectoryFeedsPopoverComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [FeedDirectoryFeedsPopoverComponent, provideTranslocoTesting()],
    }).compileComponents();
    fixture = TestBed.createComponent(FeedDirectoryFeedsPopoverComponent);
    fixture.componentRef.setInput('host', 'spiegel.de');
    fixture.componentRef.setInput('feeds', FEEDS);
    fixture.detectChanges();
  });

  const element = (): HTMLElement => fixture.nativeElement;
  const text = (node: Element | null): string =>
    (node?.textContent ?? '').replace(/\s+/g, ' ').trim();

  it('heads the list with the site and its feed count', () => {
    expect(text(element().querySelector('.site'))).toBe('spiegel.de');
    expect(text(element().querySelector('.total'))).toBe('3 feeds');
  });

  it('lists the feeds grouped by category, each by its path with the full address as a tooltip', () => {
    const groups = Array.from(element().querySelectorAll('.group')).map(text);
    const paths = Array.from(element().querySelectorAll<HTMLElement>('.path'));

    expect(groups).toEqual(['Culture · 2', 'World · 1']);
    expect(paths.map(text)).toEqual([
      '/kultur/index.rss',
      '/kultur/kino/index.rss',
      '/ausland/index.rss',
    ]);
    expect(paths[0].title).toBe('https://www.spiegel.de/kultur/index.rss');
  });

  it('opens a subscribed feed for editing instead of offering it', () => {
    const edited: string[] = [];
    fixture.componentInstance.choose.subscribe((url) => edited.push(url));
    const subscribed = element().querySelectorAll('.feed')[0];
    const button = subscribed.querySelector<HTMLButtonElement>('[data-test="edit"] button')!;
    expect(subscribed.querySelector('[data-test="subscribe"]')).toBeNull();
    expect(text(button)).toContain('Subscribed');
    expect(button.getAttribute('aria-label')).toBe('Edit subscription to /kultur/index.rss');
    button.click();
    expect(edited).toEqual(['https://www.spiegel.de/kultur/index.rss']);
  });

  it('emits the chosen feed address', () => {
    const chosen: string[] = [];
    fixture.componentInstance.choose.subscribe((url) => chosen.push(url));

    element()
      .querySelectorAll<HTMLButtonElement>('.feed [data-test="subscribe"] button')[1]
      .click();

    expect(chosen).toEqual(['https://www.spiegel.de/ausland/index.rss']);
  });

  it('names each Subscribe button after its feed', () => {
    const button = element().querySelector<HTMLButtonElement>(
      '.feed [data-test="subscribe"] button',
    )!;

    expect(button.getAttribute('aria-label')).toBe('Subscribe to /kultur/kino/index.rss');
  });

  it('asks to be dismissed from its close button', () => {
    const dismissed = jest.fn();
    fixture.componentInstance.dismiss.subscribe(dismissed);

    element().querySelector<HTMLButtonElement>('button.close')!.click();

    expect(dismissed).toHaveBeenCalled();
  });
});
