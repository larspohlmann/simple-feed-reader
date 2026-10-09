import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { EntryMetaComponent } from './entry-meta.component';
import { EntryDto, SubscriptionTagDto } from '../../models';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';

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
  title: 'A title',
  url: null,
  author: null,
  summary: null,
  excerpt: '',
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  imageRenditions: [],
  media: [],
  attachments: [],
  categories: [],
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 7,
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

const tag = (id: number, name: string): SubscriptionTagDto => ({
  id,
  name,
  color: null,
  icon: null,
  position: 0,
});

function mount(tags: SubscriptionTagDto[], testEntry: EntryDto = entry(), imageShown?: boolean) {
  TestBed.configureTestingModule({
    imports: [EntryMetaComponent, provideTranslocoTesting()],
    providers: [{ provide: EntryActionHandler, useValue: entryActions }, provideRouter([])],
  });
  const fixture = TestBed.createComponent(EntryMetaComponent);
  fixture.componentRef.setInput('entry', testEntry);
  fixture.componentRef.setInput('tags', tags);
  if (imageShown !== undefined) fixture.componentRef.setInput('imageShown', imageShown);
  fixture.detectChanges();
  return fixture;
}

describe('EntryMetaComponent', () => {
  it('renders the tag pills beside the actions', () => {
    const element = mount([tag(1, 'Tech')]).nativeElement as HTMLElement;
    expect(element.textContent).toContain('Tech');
    expect(element.querySelectorAll('app-entry-actions button').length).toBe(3);
  });

  it('still renders the actions when the entry has no tags', () => {
    const element = mount([]).nativeElement as HTMLElement;
    expect(element.querySelector('.pill')).toBeNull();
    expect(element.querySelectorAll('app-entry-actions button').length).toBe(3);
  });

  it('sends each action to the entry action handler', () => {
    const fixture = mount([]);
    const favorite = jest.fn();
    const keep = jest.fn();
    const read = jest.fn();
    entryActions.favorite.mockImplementation(favorite);
    entryActions.keep.mockImplementation(keep);
    entryActions.toggleRead.mockImplementation(read);

    const buttons = fixture.nativeElement.querySelectorAll('app-entry-actions button');
    (buttons[0] as HTMLElement).click();
    (buttons[1] as HTMLElement).click();
    (buttons[2] as HTMLElement).click();

    expect(favorite).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
    expect(keep).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
    expect(read).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
  });

  describe('Short pill', () => {
    const shortPill = (testEntry: EntryDto, imageShown?: boolean) =>
      (mount([], testEntry, imageShown).nativeElement as HTMLElement).querySelector('.pill.short');

    it('marks a Short whose card shows no image', () => {
      expect(shortPill(entry({ isShort: true }), false)).not.toBeNull();
    });

    it('takes a card to show no image unless told otherwise', () => {
      expect(shortPill(entry({ isShort: true }))).not.toBeNull();
    });

    it('leaves a Short whose image is shown to the image badge', () => {
      expect(shortPill(entry({ isShort: true }), true)).toBeNull();
    });

    it('marks no other entry', () => {
      expect(shortPill(entry(), false)).toBeNull();
    });
  });
});
