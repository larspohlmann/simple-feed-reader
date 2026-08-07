import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { EntryKickerComponent } from './entry-kicker.component';
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
    imports: [EntryKickerComponent, provideTranslocoTesting()],
    providers: [provideRouter([])],
  });
  const f = TestBed.createComponent(EntryKickerComponent);
  f.componentRef.setInput('entry', e);
  f.detectChanges();
  return f;
}

describe('EntryKickerComponent', () => {
  it('renders an oversized title and no image, even when the entry has one', () => {
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.textContent).toContain('A medium headline');
    expect(el.querySelector('img.img')).toBeNull();
  });
});
