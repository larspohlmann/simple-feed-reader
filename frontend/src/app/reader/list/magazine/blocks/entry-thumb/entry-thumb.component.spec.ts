import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../../../testing/transloco-testing';
import { EntryThumbComponent } from './entry-thumb.component';
import { EntryDto, ImageRenditionDto } from '../../../../models';
import { EntryActionHandler } from '../../../../entry/entry-actions/entry-action-handler';
import { ImageProxyService } from '../../../../../shared/proxied-image/image-proxy.service';
import { neverRecoveringImageProxy } from '../../../../../../testing/image-proxy-testing';
import { describeShortMarking } from '../../../../../../testing/short-marking-testing';

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
  imageRenditions: [],
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
  isShort: false,
  discussionUrl: null,
  comments: null,
  ...over,
});

function mount(testEntry: EntryDto) {
  TestBed.configureTestingModule({
    imports: [EntryThumbComponent, provideTranslocoTesting()],
    providers: [
      { provide: EntryActionHandler, useValue: entryActions },
      { provide: ImageProxyService, useValue: imageProxy },
      provideRouter([]),
    ],
  });
  const fixture = TestBed.createComponent(EntryThumbComponent);
  fixture.componentRef.setInput('entry', testEntry);
  fixture.detectChanges();
  return fixture;
}

describe('EntryThumbComponent', () => {
  describeShortMarking((over) => mount(entry(over)));

  it('hides an image that fails to load, without a proxy retry', () => {
    const fixture = mount(entry());
    const element = fixture.nativeElement as HTMLElement;
    element.querySelector('img.img')!.dispatchEvent(new Event('error'));
    fixture.detectChanges();
    expect(element.querySelector('img.img')).toBeNull();
    expect(imageProxy.attempts).toEqual([]);
  });

  it('renders a small image and the title', () => {
    const element = mount(entry()).nativeElement as HTMLElement;
    expect(element.querySelector('img.img')).not.toBeNull();
    expect(element.textContent).toContain('A medium headline');
  });

  it('renders without an image when the entry has none', () => {
    const element = mount(entry({ imageUrl: null })).nativeElement as HTMLElement;
    expect(element.querySelector('img.img')).toBeNull();
    expect(element.textContent).toContain('A medium headline');
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

  it('offers its renditions to the browser at the 88×66 cover box, up to a 2:1 picture', () => {
    const renditions: ImageRenditionDto[] = [
      { url: 'https://i/a-150.jpg', width: 150 },
      { url: 'https://i/a-300.jpg', width: 300 },
    ];
    const element = mount(entry({ imageRenditions: renditions })).nativeElement as HTMLElement;
    const img = element.querySelector('img.img') as HTMLImageElement;
    expect(img.getAttribute('srcset')).toBe('https://i/a-150.jpg 150w, https://i/a-300.jpg 300w');
    expect(img.getAttribute('sizes')).toBe('132px');
  });
});
