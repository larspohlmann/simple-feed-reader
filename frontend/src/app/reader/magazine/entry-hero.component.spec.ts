import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { EntryHeroComponent } from './entry-hero.component';
import { EntryDto } from '../models';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'Big headline',
  url: null,
  author: null,
  summary: 'A meaningful summary.',
  contentHtml: '<p>A meaningful summary.</p><img src="https://x/a.jpg">',
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 1,
  source: 'Src',
  isRead: false,
  isFavorite: false,
  isKept: false,
  ...over,
});

function mount(e: EntryDto) {
  TestBed.configureTestingModule({ imports: [EntryHeroComponent, provideTranslocoTesting()] });
  const f = TestBed.createComponent(EntryHeroComponent);
  f.componentRef.setInput('entry', e);
  f.detectChanges();
  return f;
}

describe('EntryHeroComponent', () => {
  it('renders the headline, source and image', () => {
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.textContent).toContain('Big headline');
    expect(el.textContent).toContain('Src');
    expect(el.querySelector('img.img')).not.toBeNull();
  });

  it('emits open on click', () => {
    const f = mount(entry());
    const open = jest.fn();
    f.componentInstance.open.subscribe(open);
    (f.nativeElement.querySelector('.hero') as HTMLElement).click();
    expect(open).toHaveBeenCalled();
  });

  it('falls back to a text hero when the image errors', () => {
    const f = mount(entry());
    f.componentInstance.imgError.set(true);
    f.detectChanges();
    expect(f.nativeElement.querySelector('img.img')).toBeNull();
    expect((f.nativeElement as HTMLElement).textContent).toContain('Big headline');
  });

  it('demotes a tiny image (tracking pixel) to a text hero', () => {
    const f = mount(entry());
    f.componentInstance.onLoad({ target: { naturalWidth: 100 } } as unknown as Event);
    f.detectChanges();
    expect(f.nativeElement.querySelector('img.img')).toBeNull();
  });
});
