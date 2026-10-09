import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../../../testing/transloco-testing';
import { EntryQuoteComponent } from './entry-quote.component';
import { EntryDto } from '../../../../models';
import { EntryActionHandler } from '../../../../entry/entry-actions/entry-action-handler';
import { describeShortPill } from '../../../../../../testing/short-marking-testing';

const entryActions = {
  favorite: jest.fn(),
  keep: jest.fn(),
  toggleRead: jest.fn(),
  open: jest.fn(),
};

beforeEach(() => {
  Object.values(entryActions).forEach((spy) => spy.mockReset());
});

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'A medium headline',
  url: null,
  author: null,
  summary: null,
  excerpt: 'First sentence here. Second sentence follows on.',
  imageUrl: 'https://i/a.jpg',
  imageWidth: 700,
  imageHeight: 400,
  imageRenditions: [],
  media: [],
  attachments: [],
  categories: [],
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 1,
  source: 'Src',
  faviconUrl: null,
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  isShort: false,
  discussionUrl: null,
  comments: null,
  ...over,
});

function mount(testEntry: EntryDto) {
  TestBed.configureTestingModule({
    imports: [EntryQuoteComponent, provideTranslocoTesting()],
    providers: [{ provide: EntryActionHandler, useValue: entryActions }, provideRouter([])],
  });
  const fixture = TestBed.createComponent(EntryQuoteComponent);
  fixture.componentRef.setInput('entry', testEntry);
  fixture.detectChanges();
  return fixture;
}

describe('EntryQuoteComponent', () => {
  describeShortPill((over) => mount(entry(over)));

  it('leads with the first sentence and never renders an image', () => {
    const element = mount(entry()).nativeElement as HTMLElement;
    expect(element.querySelector('.pull')!.textContent).toContain('First sentence here.');
    expect(element.querySelector('.pull')!.textContent).not.toContain('Second sentence');
    expect(element.querySelector('img.img')).toBeNull();
  });

  it('falls back to the whole snippet when there is no sentence break', () => {
    const element = mount(entry({ excerpt: 'One long clause with no terminator' }))
      .nativeElement as HTMLElement;
    expect(element.querySelector('.pull')!.textContent).toContain(
      'One long clause with no terminator',
    );
  });

  it('carries the three actions on its meta row', () => {
    const fixture = mount(entry());
    expect(
      fixture.nativeElement.querySelectorAll('app-entry-meta app-entry-actions button').length,
    ).toBe(3);

    const read = jest.fn();
    entryActions.toggleRead.mockImplementation(read);
    const buttons = fixture.nativeElement.querySelectorAll('app-entry-actions button');
    (buttons[2] as HTMLElement).click();
    expect(read).toHaveBeenCalled();
  });
});
