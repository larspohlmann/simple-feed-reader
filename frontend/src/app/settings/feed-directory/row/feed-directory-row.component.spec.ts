import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { LayoutService } from '../../../reader/layout.service';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import {
  FeedDirectoryFeed,
  FeedDirectoryMode,
  FeedDirectoryRow,
  modeSummaries,
} from '../feed-directory-rows';
import {
  FeedDirectoryRowComponent,
  FeedDirectorySubscribeRequest,
} from './feed-directory-row.component';

function feed(url: string, overrides: Partial<FeedDirectoryFeed> = {}): FeedDirectoryFeed {
  return {
    url,
    category: 'World / International',
    podcast: false,
    subscribed: false,
    ...overrides,
  };
}

const FEEDS = [
  feed('https://www.tagesschau.de/index~rss2.xml'),
  feed('https://www.tagesschau.de/wissen/klima/index~rss2.xml', {
    category: 'Science & Technology',
  }),
  feed('https://www.tagesschau.de/podcast.xml', { podcast: true }),
];
const ROW: FeedDirectoryRow = {
  host: 'tagesschau.de',
  country: 'DE',
  rank: 1361,
  length: 'headlines',
  languages: ['de', 'en'],
  summary: 'National news and information portal',
  feeds: FEEDS,
  byMode: modeSummaries(FEEDS),
  following: false,
  searchText: 'tagesschau.de',
};

describe('FeedDirectoryRowComponent', () => {
  let fixture: ComponentFixture<FeedDirectoryRowComponent>;
  let requests: FeedDirectorySubscribeRequest[];
  const isPhone = signal(false);

  beforeEach(() => isPhone.set(false));

  function render(row: FeedDirectoryRow, mode: FeedDirectoryMode = 'channels'): HTMLElement {
    TestBed.configureTestingModule({
      imports: [FeedDirectoryRowComponent, provideTranslocoTesting()],
      providers: [{ provide: LayoutService, useValue: { isPhone } }],
    });
    fixture = TestBed.createComponent(FeedDirectoryRowComponent);
    fixture.componentRef.setInput('row', row);
    fixture.componentRef.setInput('mode', mode);
    requests = [];
    fixture.componentInstance.subscribe.subscribe((request) => requests.push(request));
    fixture.detectChanges();
    return fixture.nativeElement;
  }

  const text = (element: Element | null): string =>
    (element?.textContent ?? '').replace(/\s+/g, ' ').trim();

  it('shows the host, the meta line, the summary and the categories in the mode', () => {
    const element = render(ROW);

    expect(text(element.querySelector('.name'))).toBe('tagesschau.de');
    expect(
      Array.from(element.querySelectorAll('.meta span')).map((part) => part.textContent),
    ).toEqual(['Germany', 'German, English', 'Headlines', 'Rank 1,361']);
    expect(text(element.querySelector('.summary'))).toBe('National news and information portal');
    expect(Array.from(element.querySelectorAll('.chip')).map((chip) => chip.textContent)).toEqual([
      'World / International',
      'Science & Technology',
    ]);
  });

  it('leaves out the length and the rank when the directory has none', () => {
    const element = render({ ...ROW, length: null, rank: null, summary: null });

    expect(
      Array.from(element.querySelectorAll('.meta span')).map((part) => part.textContent),
    ).toEqual(['Germany', 'German, English']);
    expect(element.querySelector('.summary')).toBeNull();
  });

  it('shows the Following badge only for a followed site', () => {
    expect(render(ROW).querySelector('.follow')).toBeNull();
    TestBed.resetTestingModule();

    expect(text(render({ ...ROW, following: true }).querySelector('.follow'))).toContain(
      'Following',
    );
  });

  it('offers Subscribe for the one feed in the mode and emits the row with its button', () => {
    const element = render(ROW, 'podcasts');
    const button = element.querySelector<HTMLElement>('[data-test="subscribe"]')!;

    expect(text(button)).toContain('Subscribe');
    button.click();

    expect(requests).toEqual([{ row: ROW, anchor: button }]);
  });

  it('opens the subscribed feed for editing from its Subscribed status', () => {
    const url = 'https://www.tagesschau.de/podcast.xml';
    const element = render(
      { ...ROW, feeds: [feed(url, { podcast: true, subscribed: true })] },
      'podcasts',
    );
    const edits: string[] = [];
    fixture.componentInstance.edit.subscribe((edited) => edits.push(edited));
    expect(element.querySelector('[data-test="subscribe"]')).toBeNull();
    const status = element.querySelector<HTMLElement>('[data-test="edit"] button')!;
    expect(text(status)).toContain('Subscribed');
    expect(status.getAttribute('aria-label')).toBe('Edit subscription');
    status.click();
    expect(edits).toEqual([url]);
  });

  it('offers the feeds in the mode as a list and emits the row with its button', () => {
    const element = render(ROW);
    const button = element.querySelector<HTMLElement>('[data-test="feeds"]')!;

    expect(text(button)).toContain('2 feeds');
    button.click();

    expect(requests).toEqual([{ row: ROW, anchor: button }]);
  });

  it('counts the subscribed feeds on the list button', () => {
    const element = render({
      ...ROW,
      feeds: [
        feed('https://a.de/1', { subscribed: true }),
        feed('https://a.de/2'),
        feed('https://a.de/3'),
      ],
    });

    expect(text(element.querySelector('[data-test="feeds"]'))).toContain('3 feeds · 1 subscribed');
  });

  it('names the list button in full, so the count of subscribed feeds survives a phone', () => {
    const element = render({
      ...ROW,
      feeds: [feed('https://a.de/1', { subscribed: true }), feed('https://a.de/2')],
    });
    const button = element.querySelector('[data-test="feeds"] button')!;

    expect(button.getAttribute('aria-label')).toBe('2 feeds · 1 subscribed');
    expect(text(element.querySelector('[data-test="feeds"] .subscribed-part'))).toBe(
      '· 1 subscribed',
    );
  });

  it('leaves the list button to its text while no feed is subscribed', () => {
    const element = render(ROW);

    expect(element.querySelector('[data-test="feeds"] button')!.hasAttribute('aria-label')).toBe(
      false,
    );
  });

  it('keeps the Following label for screen readers only on a phone', () => {
    isPhone.set(true);
    const element = render({ ...ROW, following: true });
    const label = element.querySelector('.follow-label')!;

    expect(label.classList).toContain('sr-only');
    expect(label.textContent).toBe('Following');
  });

  it('shows the Following label on a wide screen', () => {
    const element = render({ ...ROW, following: true });

    expect(element.querySelector('.follow-label')!.classList).not.toContain('sr-only');
  });
});
