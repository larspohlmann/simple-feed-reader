import { TestBed } from '@angular/core/testing';
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
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 1,
  source: 'Golem',
  isRead: false,
  isFavorite: false,
  isKept: false,
};

describe('EntryCompactComponent', () => {
  function mount() {
    TestBed.configureTestingModule({ imports: [EntryCompactComponent] });
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
    TestBed.configureTestingModule({ imports: [EntryCompactComponent] });
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
      imports: [EntryCompactComponent],
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
      imports: [EntryCompactComponent],
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
});
