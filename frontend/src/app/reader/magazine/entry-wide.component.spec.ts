import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { EntryWideComponent } from './entry-wide.component';
import { EntryDto } from '../models';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'A medium headline',
  url: null,
  author: null,
  summary: 'A meaningful summary.',
  contentHtml: null,
  imageUrl: 'https://i/a.jpg',
  imageWidth: 700,
  imageHeight: 400,
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 1,
  source: 'Src',
  faviconUrl: null,
  isRead: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  ...over,
});

function mount(e: EntryDto) {
  TestBed.configureTestingModule({
    imports: [EntryWideComponent, provideTranslocoTesting()],
    providers: [provideRouter([])],
  });
  const f = TestBed.createComponent(EntryWideComponent);
  f.componentRef.setInput('entry', e);
  f.detectChanges();
  return f;
}

describe('EntryWideComponent', () => {
  it('renders a full-width image and the title, but no snippet', () => {
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.querySelector('img.img')).not.toBeNull();
    expect(el.textContent).toContain('A medium headline');
    expect(el.textContent).not.toContain('A meaningful summary.');
  });

  it('emits open on click', () => {
    const f = mount(entry());
    let opened: EntryDto | null = null;
    f.componentInstance.open.subscribe((e: EntryDto) => (opened = e));
    (f.nativeElement as HTMLElement).querySelector('article')!.dispatchEvent(new Event('click'));
    expect(opened).not.toBeNull();
  });

  it('carries the three actions on its meta row', () => {
    const f = mount(entry());
    expect(f.nativeElement.querySelectorAll('app-entry-meta app-entry-actions button').length).toBe(
      3,
    );

    const read = jest.fn();
    f.componentInstance.read.subscribe(read);
    const buttons = f.nativeElement.querySelectorAll('app-entry-actions button');
    (buttons[2] as HTMLElement).click();
    expect(read).toHaveBeenCalled();
  });
});
