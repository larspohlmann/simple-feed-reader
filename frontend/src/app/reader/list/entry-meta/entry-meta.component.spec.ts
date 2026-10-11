import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { EntryMetaComponent } from './entry-meta.component';
import { EntryDto, SubscriptionTagDto } from '../../models';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { SHORT_PLAYER_URL } from '../../../../testing/short-marking-testing';
import { anEntry } from '../../../../testing/entry-testing';

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

function mount(tags: SubscriptionTagDto[], testEntry: EntryDto = anEntry(), imageShown?: boolean) {
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
      expect(shortPill(anEntry({ shortPlayerUrl: SHORT_PLAYER_URL }), false)).not.toBeNull();
    });

    it('takes a card to show no image unless told otherwise', () => {
      expect(shortPill(anEntry({ shortPlayerUrl: SHORT_PLAYER_URL }))).not.toBeNull();
    });

    it('leaves a Short whose image is shown to the image badge', () => {
      expect(shortPill(anEntry({ shortPlayerUrl: SHORT_PLAYER_URL }), true)).toBeNull();
    });

    it('marks no other entry', () => {
      expect(shortPill(anEntry(), false)).toBeNull();
    });
  });
});
