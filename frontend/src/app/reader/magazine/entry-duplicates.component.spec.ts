import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { EntryDto } from '../models';
import { EntryDuplicatesComponent } from './entry-duplicates.component';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'Main',
  url: null,
  author: null,
  summary: null,
  contentHtml: null,
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  media: [],
  attachments: [],
  publishedAt: '2026-07-05T09:00:00Z',
  createdAt: '2026-07-05T09:00:00Z',
  subscriptionId: 1,
  source: 'tagesschau',
  faviconUrl: null,
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  ...over,
});

function mount(e: EntryDto) {
  TestBed.configureTestingModule({
    imports: [EntryDuplicatesComponent, provideTranslocoTesting()],
    providers: [provideRouter([])],
  });
  const f = TestBed.createComponent(EntryDuplicatesComponent);
  f.componentRef.setInput('entry', e);
  f.detectChanges();
  return f;
}

it('renders nothing without duplicates', () => {
  const el = mount(entry()).nativeElement as HTMLElement;
  expect(el.querySelector('.also-foot')).toBeNull();
});

it('renders one chip per duplicate with its source', () => {
  const dup = entry({ id: 2, source: 'NDR Schleswig-Holstein' });
  const el = mount(entry({ duplicates: [dup] })).nativeElement as HTMLElement;
  const chips = el.querySelectorAll('.also-entry');
  expect(chips.length).toBe(1);
  expect(chips[0].textContent).toContain('NDR Schleswig-Holstein');
});
