import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../../../testing/transloco-testing';
import { provideRouter } from '@angular/router';
import { EntryCompactComponent } from './entry-compact.component';
import { EntryDto, SubscriptionTagDto } from '../../../../models';
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

const tag = (id: number, name: string): SubscriptionTagDto => ({
  id,
  name,
  color: null,
  icon: null,
  position: 0,
});

const entry: EntryDto = {
  id: 3,
  title: 'One-liner headline',
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
  subscriptionId: 1,
  source: 'Golem',
  faviconUrl: null,
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  isShort: false,
  discussionUrl: null,
  comments: null,
};

describe('EntryCompactComponent', () => {
  function mount() {
    TestBed.configureTestingModule({
      imports: [EntryCompactComponent, provideTranslocoTesting()],
      providers: [{ provide: EntryActionHandler, useValue: entryActions }, provideRouter([])],
    });
    const fixture = TestBed.createComponent(EntryCompactComponent);
    fixture.componentRef.setInput('entry', entry);
    fixture.detectChanges();
    return fixture;
  }

  it('renders the source and title', () => {
    const element = mount().nativeElement as HTMLElement;
    expect(element.textContent).toContain('One-liner headline');
    expect(element.textContent).toContain('Golem');
  });

  it('shows a one-line dek when the entry has a summary (#515)', () => {
    TestBed.configureTestingModule({
      imports: [EntryCompactComponent, provideTranslocoTesting()],
      providers: [{ provide: EntryActionHandler, useValue: entryActions }, provideRouter([])],
    });
    const fixture = TestBed.createComponent(EntryCompactComponent);
    fixture.componentRef.setInput('entry', { ...entry, excerpt: 'A short description.' });
    fixture.detectChanges();
    const dek = (fixture.nativeElement as HTMLElement).querySelector('.dek');
    expect(dek).not.toBeNull();
    expect(dek!.textContent).toContain('A short description.');
  });

  it('stays title-only for a headline-only entry — no empty dek (#515)', () => {
    // The fixture carries excerpt: '', so snippet() is empty and the @if must
    // render no dek element at all.
    const element = mount().nativeElement as HTMLElement;
    expect(element.querySelector('.dek')).toBeNull();
  });

  it('hides the source when showSource is false', () => {
    TestBed.configureTestingModule({
      providers: [{ provide: EntryActionHandler, useValue: entryActions }],
      imports: [EntryCompactComponent, provideTranslocoTesting()],
    });
    const fixture = TestBed.createComponent(EntryCompactComponent);
    fixture.componentRef.setInput('entry', entry);
    fixture.componentRef.setInput('showSource', false);
    fixture.detectChanges();
    expect(
      (fixture.nativeElement as HTMLElement).querySelector('.kicker')!.textContent,
    ).not.toContain('Golem');
  });

  it('shows tag pills when standalone', () => {
    TestBed.configureTestingModule({
      imports: [EntryCompactComponent, provideTranslocoTesting()],
      providers: [{ provide: EntryActionHandler, useValue: entryActions }, provideRouter([])],
    });
    const fixture = TestBed.createComponent(EntryCompactComponent);
    fixture.componentRef.setInput('entry', entry);
    fixture.componentRef.setInput('tags', [tag(2, 'Tech')]);
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).querySelector('a.pill')!.textContent).toContain(
      'Tech',
    );
  });

  it('hides tag pills inside a source group (showSource=false)', () => {
    TestBed.configureTestingModule({
      imports: [EntryCompactComponent, provideTranslocoTesting()],
      providers: [{ provide: EntryActionHandler, useValue: entryActions }, provideRouter([])],
    });
    const fixture = TestBed.createComponent(EntryCompactComponent);
    fixture.componentRef.setInput('entry', entry);
    fixture.componentRef.setInput('tags', [tag(2, 'Tech')]);
    fixture.componentRef.setInput('showSource', false);
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).querySelector('a.pill')).toBeNull();
  });

  it('emits open on click and on Enter', () => {
    const fixture = mount();
    const open = jest.fn();
    entryActions.open.mockImplementation(open);
    const row = fixture.nativeElement.querySelector('.compact') as HTMLElement;
    row.click();
    row.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    expect(open).toHaveBeenCalledTimes(2);
  });

  it('keeps standalone actions on the bottom meta row', () => {
    const element = mount().nativeElement as HTMLElement;
    const actions = element.querySelector('app-entry-actions');
    expect(actions).not.toBeNull();
    expect(actions!.closest('p.kicker')).toBeNull();
    expect(actions!.closest('app-entry-meta')).not.toBeNull();
  });

  it('moves grouped actions onto the kicker line', () => {
    const fixture = mount();
    fixture.componentRef.setInput('showSource', false);
    fixture.detectChanges();
    const actions = (fixture.nativeElement as HTMLElement).querySelector('app-entry-actions');

    expect(actions).not.toBeNull();
    expect(actions!.closest('p.kicker')).not.toBeNull();
    expect(actions!.closest('app-entry-meta')).toBeNull();
  });

  it('renders the three actions with showSource false and no tag pills to sit beside', () => {
    const fixture = mount();
    fixture.componentRef.setInput('showSource', false);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('app-entry-pills .pill')).toBeNull();
    expect(element.querySelectorAll('app-entry-actions button').length).toBe(3);
  });

  it('emits keep without opening the entry', () => {
    const fixture = mount();
    const keep = jest.fn();
    const open = jest.fn();
    entryActions.keep.mockImplementation(keep);
    entryActions.open.mockImplementation(open);

    const buttons = fixture.nativeElement.querySelectorAll('app-entry-actions button');
    (buttons[1] as HTMLElement).click();

    expect(keep).toHaveBeenCalled();
    expect(open).not.toHaveBeenCalled();
  });
});
