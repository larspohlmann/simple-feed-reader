import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { provideRouter } from '@angular/router';
import { SourceGroupComponent } from './source-group.component';
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

const tag = (id: number, name: string): SubscriptionTagDto => ({
  id,
  name,
  color: null,
  icon: null,
  position: 0,
});

const entryAt = (id: number): EntryDto => ({
  id,
  title: `t${id}`,
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
  source: 'heise',
  faviconUrl: null,
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  isShort: false,
  imageAspectRatio: null,
  discussionUrl: null,
  comments: null,
});

describe('SourceGroupComponent', () => {
  function mount(entries: EntryDto[], previewCount: number) {
    TestBed.configureTestingModule({
      imports: [SourceGroupComponent, provideTranslocoTesting()],
      providers: [{ provide: EntryActionHandler, useValue: entryActions }, provideRouter([])],
    });
    const fixture = TestBed.createComponent(SourceGroupComponent);
    fixture.componentRef.setInput('source', 'heise');
    fixture.componentRef.setInput('subscriptionId', 7);
    fixture.componentRef.setInput('entries', entries);
    fixture.componentRef.setInput('previewCount', previewCount);
    fixture.componentRef.setInput('tags', []);
    fixture.detectChanges();
    return fixture;
  }

  it('previews previewCount rows and counts the hidden tail', () => {
    const element = mount(
      [entryAt(1), entryAt(2), entryAt(3), entryAt(4), entryAt(5), entryAt(6), entryAt(7)],
      4,
    ).nativeElement as HTMLElement;
    expect(element.textContent).toContain('heise');
    expect(element.querySelectorAll('app-entry-compact').length).toBe(4);
    expect(element.querySelector('.more')!.textContent).toContain('3 more from heise');
  });

  it('renders no more indicator when the tail fits the preview', () => {
    const element = mount([entryAt(1), entryAt(2), entryAt(3)], 3).nativeElement as HTMLElement;
    expect(element.querySelector('.more')).toBeNull();
  });

  it('shows the feed tags as pills once, on the group header', () => {
    const fixture = mount([entryAt(1), entryAt(2), entryAt(3), entryAt(4), entryAt(5)], 4);
    fixture.componentRef.setInput('tags', [tag(2, 'Tech')]);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    const pills = element.querySelectorAll('a.pill');
    expect(pills.length).toBe(1);
    expect(pills[0].textContent).toContain('Tech');
    // The header carries the pills; the inner compacts do not repeat them.
    expect(element.querySelector('.ghead a.pill')).not.toBeNull();
  });

  it('re-emits open from an inner item', () => {
    const fixture = mount([entryAt(1), entryAt(2), entryAt(3), entryAt(4), entryAt(5)], 4);
    const open = jest.fn();
    entryActions.open.mockImplementation(open);
    (fixture.nativeElement.querySelector('.compact') as HTMLElement).click();
    expect(open).toHaveBeenCalled();
  });

  it('expands to reveal the whole tail and collapses again', () => {
    const fixture = mount(
      [entryAt(1), entryAt(2), entryAt(3), entryAt(4), entryAt(5), entryAt(6), entryAt(7)],
      4,
    );
    const element = fixture.nativeElement as HTMLElement;
    const button = element.querySelector('button.more') as HTMLButtonElement;
    expect(button.getAttribute('aria-expanded')).toBe('false');
    expect(element.querySelectorAll('app-entry-compact').length).toBe(4);

    button.click();
    fixture.detectChanges();
    expect(element.querySelectorAll('app-entry-compact').length).toBe(7);
    expect(button.getAttribute('aria-expanded')).toBe('true');
    expect(button.textContent).toContain('Show less');

    button.click();
    fixture.detectChanges();
    expect(element.querySelectorAll('app-entry-compact').length).toBe(4);
    expect(button.getAttribute('aria-expanded')).toBe('false');
  });

  it('forwards an action from the row it was pressed on', () => {
    const fixture = mount([entryAt(1), entryAt(2), entryAt(3)], 3);
    const favorite = jest.fn();
    entryActions.favorite.mockImplementation(favorite);

    const rows = fixture.nativeElement.querySelectorAll('app-entry-compact');
    const secondRowStar = rows[1].querySelectorAll('app-entry-actions button')[0] as HTMLElement;
    secondRowStar.click();

    expect(favorite).toHaveBeenCalledWith(expect.objectContaining({ id: 2 }));
  });

  it('forwards keep and read as well', () => {
    const fixture = mount([entryAt(1)], 1);
    const keep = jest.fn();
    const read = jest.fn();
    entryActions.keep.mockImplementation(keep);
    entryActions.toggleRead.mockImplementation(read);

    const buttons = fixture.nativeElement.querySelectorAll('app-entry-actions button');
    (buttons[1] as HTMLElement).click();
    (buttons[2] as HTMLElement).click();

    expect(keep).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
    expect(read).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
  });
});
