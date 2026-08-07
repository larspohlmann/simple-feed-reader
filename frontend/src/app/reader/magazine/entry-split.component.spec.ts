import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { EntrySplitComponent } from './entry-split.component';
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

function mount(e: EntryDto, side: 'left' | 'right' = 'right') {
  TestBed.configureTestingModule({
    imports: [EntrySplitComponent, provideTranslocoTesting()],
    providers: [provideRouter([])],
  });
  const f = TestBed.createComponent(EntrySplitComponent);
  f.componentRef.setInput('entry', e);
  f.componentRef.setInput('imageSide', side);
  f.detectChanges();
  return f;
}

describe('EntrySplitComponent', () => {
  it('renders the title, snippet and image', () => {
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.textContent).toContain('A medium headline');
    expect(el.textContent).toContain('A meaningful summary.');
    expect(el.querySelector('img.img')).not.toBeNull();
  });

  it('flips the image to the left on request', () => {
    const el = mount(entry(), 'left').nativeElement as HTMLElement;
    expect(el.querySelector('.split.img-left')).not.toBeNull();
  });

  it('gives a portrait image a portrait side box, bounded at 3:4', () => {
    const f = mount(entry({ imageWidth: 900, imageHeight: 1100 }));
    const ratio = () => {
      f.detectChanges();
      return ((f.nativeElement as HTMLElement).querySelector('img.img') as HTMLImageElement).style
        .aspectRatio;
    };
    const swap = (over: Partial<EntryDto>) => f.componentRef.setInput('entry', entry(over));

    // A moderate portrait keeps its true ratio…
    expect(ratio()).toBe('900 / 1100');
    // …an extreme one is clamped to 3:4 (height = width * 4/3 = 1200).
    swap({ imageWidth: 900, imageHeight: 3000 });
    expect(ratio()).toBe('900 / 1200');
    // …a wide landscape is clamped to 3:2 (height = width * 2/3 = 600).
    swap({ imageWidth: 900, imageHeight: 200 });
    expect(ratio()).toBe('900 / 600');
    // …unknown dimensions keep the 3:2 default.
    swap({ imageWidth: null, imageHeight: null });
    expect(ratio()).toBe('3 / 2');
  });

  it('emits open on click', () => {
    const f = mount(entry());
    let opened: EntryDto | null = null;
    f.componentInstance.open.subscribe((e: EntryDto) => (opened = e));
    (f.nativeElement as HTMLElement).querySelector('article')!.dispatchEvent(new Event('click'));
    expect(opened).not.toBeNull();
  });

  it('resets the image-error gate when the host recycles the component for a new entry', () => {
    const f = mount(entry());
    f.componentInstance.imgError.set(true);
    f.detectChanges();

    f.componentRef.setInput('entry', entry({ id: 2 }));
    f.detectChanges();

    expect(f.componentInstance.imgError()).toBe(false);
  });
});
