import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { provideRouter } from '@angular/router';
import { EntryCompactComponent } from './entry-compact.component';
import { EntryDto, SubscriptionTagDto } from '../models';

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
  contentHtml: null,
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 1,
  source: 'Golem',
  faviconUrl: null,
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
};

describe('EntryCompactComponent', () => {
  function mount() {
    TestBed.configureTestingModule({
      imports: [EntryCompactComponent, provideTranslocoTesting()],
      providers: [provideRouter([])],
    });
    const f = TestBed.createComponent(EntryCompactComponent);
    f.componentRef.setInput('entry', entry);
    f.detectChanges();
    return f;
  }

  it('renders the source and title', () => {
    const el = mount().nativeElement as HTMLElement;
    expect(el.textContent).toContain('One-liner headline');
    expect(el.textContent).toContain('Golem');
  });

  it('shows a one-line dek when the entry has a summary (#515)', () => {
    TestBed.configureTestingModule({
      imports: [EntryCompactComponent, provideTranslocoTesting()],
      providers: [provideRouter([])],
    });
    const f = TestBed.createComponent(EntryCompactComponent);
    f.componentRef.setInput('entry', { ...entry, summary: 'A short description.' });
    f.detectChanges();
    const dek = (f.nativeElement as HTMLElement).querySelector('.dek');
    expect(dek).not.toBeNull();
    expect(dek!.textContent).toContain('A short description.');
  });

  it('stays title-only for a headline-only entry — no empty dek (#515)', () => {
    // The fixture carries summary: null and contentHtml: null, so snippet() is
    // empty and the @if must render no dek element at all.
    const el = mount().nativeElement as HTMLElement;
    expect(el.querySelector('.dek')).toBeNull();
  });

  it('hides the source when showSource is false', () => {
    TestBed.configureTestingModule({ imports: [EntryCompactComponent, provideTranslocoTesting()] });
    const f = TestBed.createComponent(EntryCompactComponent);
    f.componentRef.setInput('entry', entry);
    f.componentRef.setInput('showSource', false);
    f.detectChanges();
    expect((f.nativeElement as HTMLElement).querySelector('.kicker')!.textContent).not.toContain(
      'Golem',
    );
  });

  it('shows tag pills when standalone', () => {
    TestBed.configureTestingModule({
      imports: [EntryCompactComponent, provideTranslocoTesting()],
      providers: [provideRouter([])],
    });
    const f = TestBed.createComponent(EntryCompactComponent);
    f.componentRef.setInput('entry', entry);
    f.componentRef.setInput('tags', [tag(2, 'Tech')]);
    f.detectChanges();
    expect((f.nativeElement as HTMLElement).querySelector('a.pill')!.textContent).toContain('Tech');
  });

  it('hides tag pills inside a source group (showSource=false)', () => {
    TestBed.configureTestingModule({
      imports: [EntryCompactComponent, provideTranslocoTesting()],
      providers: [provideRouter([])],
    });
    const f = TestBed.createComponent(EntryCompactComponent);
    f.componentRef.setInput('entry', entry);
    f.componentRef.setInput('tags', [tag(2, 'Tech')]);
    f.componentRef.setInput('showSource', false);
    f.detectChanges();
    expect((f.nativeElement as HTMLElement).querySelector('a.pill')).toBeNull();
  });

  it('emits open on click and on Enter', () => {
    const f = mount();
    const open = jest.fn();
    f.componentInstance.open.subscribe(open);
    const row = f.nativeElement.querySelector('.compact') as HTMLElement;
    row.click();
    row.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    expect(open).toHaveBeenCalledTimes(2);
  });

  it('drops the actions onto their own bottom meta row, not the kicker line (#486)', () => {
    const el = mount().nativeElement as HTMLElement;
    const actions = el.querySelector('app-entry-actions');
    expect(actions).not.toBeNull();
    // The actions now ride the shared meta row (tags + actions, right-aligned at
    // the card's bottom), not the kicker's own <p>.
    expect(actions!.closest('p.kicker')).toBeNull();
    expect(actions!.closest('app-entry-meta')).not.toBeNull();
  });

  it('renders the three actions with showSource false and no tag pills to sit beside', () => {
    const f = mount();
    f.componentRef.setInput('showSource', false);
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;
    // The meta row still renders (for the actions), but with no tags passed it
    // shows no pills.
    expect(el.querySelector('app-source-tags .pill')).toBeNull();
    expect(el.querySelectorAll('app-entry-actions button').length).toBe(3);
  });

  it('emits keep without opening the entry', () => {
    const f = mount();
    const keep = jest.fn();
    const open = jest.fn();
    f.componentInstance.keep.subscribe(keep);
    f.componentInstance.open.subscribe(open);

    const buttons = f.nativeElement.querySelectorAll('app-entry-actions button');
    (buttons[1] as HTMLElement).click();

    expect(keep).toHaveBeenCalled();
    expect(open).not.toHaveBeenCalled();
  });
});
