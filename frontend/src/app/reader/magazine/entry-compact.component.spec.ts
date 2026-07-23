import { TestBed } from '@angular/core/testing';
import { EntryCompactComponent } from './entry-compact.component';
import { EntryDto } from '../models';

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
