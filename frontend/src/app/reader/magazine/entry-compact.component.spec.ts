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
  isRead: false,
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

  it('hangs the actions on the kicker line, not on a row of their own', () => {
    const el = mount().nativeElement as HTMLElement;
    const actions = el.querySelector('app-entry-actions');
    expect(actions).not.toBeNull();
    // Proves the actions render inside the kicker's own <p>, not as a sibling
    // block below it — which is what would drop the icons onto a second line.
    expect(actions!.closest('p.kicker')).not.toBeNull();
    expect(el.querySelector('app-entry-meta')).toBeNull();
  });

  it('renders the three actions with showSource false and no tags to sit beside', () => {
    const f = mount();
    f.componentRef.setInput('showSource', false);
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;
    expect(el.querySelector('app-source-tags')).toBeNull();
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
