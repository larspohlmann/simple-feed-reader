import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../../../testing/transloco-testing';
import { EntrySplitComponent } from './entry-split.component';
import { EntryDto } from '../../../../models';
import { EntryActionHandler } from '../../../../entry/entry-actions/entry-action-handler';
import { ImageProxyService } from '../../../../../shared/proxied-image/image-proxy.service';
import { neverRecoveringImageProxy } from '../../../../../../testing/image-proxy-testing';

const entryActions = {
  favorite: jest.fn(),
  keep: jest.fn(),
  toggleRead: jest.fn(),
  open: jest.fn(),
};

let imageProxy: ReturnType<typeof neverRecoveringImageProxy>;

beforeEach(() => {
  Object.values(entryActions).forEach((spy) => spy.mockReset());
  imageProxy = neverRecoveringImageProxy();
});

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'A medium headline',
  url: null,
  author: null,
  summary: 'A meaningful summary.',
  excerpt: 'A meaningful summary.',
  imageUrl: 'https://i/a.jpg',
  imageWidth: 700,
  imageHeight: 400,
  media: [],
  attachments: [],
  categories: [],
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 1,
  source: 'Src',
  faviconUrl: null,
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  discussionUrl: null,
  comments: null,
  ...over,
});

function mount(testEntry: EntryDto, side: 'left' | 'right' = 'right') {
  TestBed.configureTestingModule({
    imports: [EntrySplitComponent, provideTranslocoTesting()],
    providers: [
      { provide: EntryActionHandler, useValue: entryActions },
      { provide: ImageProxyService, useValue: imageProxy },
      provideRouter([]),
    ],
  });
  const fixture = TestBed.createComponent(EntrySplitComponent);
  fixture.componentRef.setInput('entry', testEntry);
  fixture.componentRef.setInput('imageSide', side);
  fixture.detectChanges();
  return fixture;
}

describe('EntrySplitComponent', () => {
  it('hides an image that fails to load, without a proxy retry', () => {
    const fixture = mount(entry());
    const element = fixture.nativeElement as HTMLElement;
    element.querySelector('img.img')!.dispatchEvent(new Event('error'));
    fixture.detectChanges();
    expect(element.querySelector('img.img')).toBeNull();
    expect(imageProxy.attempts).toEqual([]);
  });

  it('renders the title, snippet and image', () => {
    const element = mount(entry()).nativeElement as HTMLElement;
    expect(element.textContent).toContain('A medium headline');
    expect(element.textContent).toContain('A meaningful summary.');
    expect(element.querySelector('img.img')).not.toBeNull();
  });

  it('flips the image to the left on request', () => {
    const element = mount(entry(), 'left').nativeElement as HTMLElement;
    expect(element.querySelector('.split.img-left')).not.toBeNull();
  });

  it('gives a portrait image a portrait side box, bounded at 3:4', () => {
    const fixture = mount(entry({ imageWidth: 900, imageHeight: 1100 }));
    const ratio = () => {
      fixture.detectChanges();
      return ((fixture.nativeElement as HTMLElement).querySelector('img.img') as HTMLImageElement)
        .style.aspectRatio;
    };
    const swap = (over: Partial<EntryDto>) => fixture.componentRef.setInput('entry', entry(over));

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
    const fixture = mount(entry());
    let opened: EntryDto | null = null;
    entryActions.open.mockImplementation((openedEntry: EntryDto) => (opened = openedEntry));
    (fixture.nativeElement as HTMLElement)
      .querySelector('article')!
      .dispatchEvent(new Event('click'));
    expect(opened).not.toBeNull();
  });

  it('resets the image-error gate when the host recycles the component for a new entry', () => {
    const fixture = mount(entry());
    fixture.componentInstance.imgError.set(true);
    fixture.detectChanges();

    fixture.componentRef.setInput('entry', entry({ id: 2 }));
    fixture.detectChanges();

    expect(fixture.componentInstance.imgError()).toBe(false);
  });

  it('carries the three actions on its meta row', () => {
    const fixture = mount(entry());
    expect(
      fixture.nativeElement.querySelectorAll('app-entry-meta app-entry-actions button').length,
    ).toBe(3);

    const favorite = jest.fn();
    entryActions.favorite.mockImplementation(favorite);
    const buttons = fixture.nativeElement.querySelectorAll('app-entry-actions button');
    (buttons[0] as HTMLElement).click();
    expect(favorite).toHaveBeenCalled();
  });
});
