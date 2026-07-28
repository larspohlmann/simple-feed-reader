import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { EntryQuoteComponent } from './entry-quote.component';
import { EntryDto } from '../models';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'A medium headline',
  url: null,
  author: null,
  summary: 'First sentence here. Second sentence follows on.',
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
  ...over,
});

function mount(e: EntryDto) {
  TestBed.configureTestingModule({
    imports: [EntryQuoteComponent, provideTranslocoTesting()],
    providers: [provideRouter([])],
  });
  const f = TestBed.createComponent(EntryQuoteComponent);
  f.componentRef.setInput('entry', e);
  f.detectChanges();
  return f;
}

describe('EntryQuoteComponent', () => {
  it('leads with the first sentence and never renders an image', () => {
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.querySelector('.pull')!.textContent).toContain('First sentence here.');
    expect(el.querySelector('.pull')!.textContent).not.toContain('Second sentence');
    expect(el.querySelector('img.img')).toBeNull();
  });

  it('falls back to the whole snippet when there is no sentence break', () => {
    const el = mount(entry({ summary: 'One long clause with no terminator' }))
      .nativeElement as HTMLElement;
    expect(el.querySelector('.pull')!.textContent).toContain('One long clause with no terminator');
  });
});
