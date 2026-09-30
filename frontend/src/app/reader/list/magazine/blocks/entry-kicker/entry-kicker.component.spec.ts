import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../../../testing/transloco-testing';
import { EntryKickerComponent } from './entry-kicker.component';
import { EntryDto } from '../../../../models';
import { EntryActionHandler } from '../../../../entry/entry-actions/entry-action-handler';

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
  summary: 'A meaningful summary.',
  excerpt: 'A meaningful summary.',
  imageUrl: 'https://i/a.jpg',
  imageWidth: 700,
  imageHeight: 400,
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
  discussionUrl: null,
  comments: null,
  ...over,
});

function mount(testEntry: EntryDto) {
  TestBed.configureTestingModule({
    imports: [EntryKickerComponent, provideTranslocoTesting()],
    providers: [{ provide: EntryActionHandler, useValue: entryActions }, provideRouter([])],
  });
  const fixture = TestBed.createComponent(EntryKickerComponent);
  fixture.componentRef.setInput('entry', testEntry);
  fixture.detectChanges();
  return fixture;
}

describe('EntryKickerComponent', () => {
  it('renders an oversized title and no image, even when the entry has one', () => {
    const element = mount(entry()).nativeElement as HTMLElement;
    expect(element.textContent).toContain('A medium headline');
    expect(element.querySelector('img.img')).toBeNull();
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
