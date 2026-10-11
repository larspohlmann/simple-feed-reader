import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../../../testing/transloco-testing';
import { EntryMediaComponent } from './entry-media.component';
import { EntryDto, EntryMediumDto } from '../../../../models';
import { EntryActionHandler } from '../../../../entry/entry-actions/entry-action-handler';
import { isHlsManaged } from '../../../../article/decorators/hls-streams';

let pause: jest.SpyInstance;

beforeEach(() => {
  pause = jest.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => undefined);
});

afterEach(() => pause.mockRestore());

const entryActions = {
  favorite: jest.fn(),
  keep: jest.fn(),
  toggleRead: jest.fn(),
  open: jest.fn(),
};

beforeEach(() => {
  Object.values(entryActions).forEach((spy) => spy.mockReset());
});

const image = (index: number, width = 800, height = 600): EntryMediumDto => ({
  url: `https://cdn.test/${index}.jpg`,
  kind: 'image',
  width,
  height,
});
const clip: EntryMediumDto = {
  url: 'https://video.test/playlist.m3u8',
  kind: 'video',
  width: 1080,
  height: 1920,
  previewImageUrl: 'https://video.test/thumb.jpg',
};

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'A sunrise with Nutmeg the donkey',
  url: 'https://bsky.app/profile/someone/post/1',
  author: null,
  summary: null,
  excerpt: 'A sunrise with Nutmeg the donkey',
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  imageRenditions: [],
  media: [image(1)],
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
  titleDerived: true,
  linkCards: [],
  pending: false,
  imageAspectRatio: null,
  discussionUrl: null,
  comments: null,
  ...over,
});

async function mountFixture(testEntry: EntryDto) {
  TestBed.configureTestingModule({
    imports: [EntryMediaComponent, provideTranslocoTesting()],
    providers: [{ provide: EntryActionHandler, useValue: entryActions }, provideRouter([])],
  });
  const fixture = TestBed.createComponent(EntryMediaComponent);
  fixture.componentRef.setInput('entry', testEntry);
  fixture.detectChanges();
  await fixture.whenStable();
  return fixture;
}

async function mount(testEntry: EntryDto) {
  return (await mountFixture(testEntry)).nativeElement as HTMLElement;
}

const sighting = (target: Element, isIntersecting: boolean) =>
  ({ target, isIntersecting }) as unknown as IntersectionObserverEntry;

const press = (target: Element, key: string) =>
  target.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));

describe('EntryMediaComponent', () => {
  it('shows the post text as a caption above the media', async () => {
    const element = await mount(entry());

    expect(element.querySelector('.caption')!.textContent).toContain('A sunrise with Nutmeg');
  });

  it('shows no caption for a post without text', async () => {
    const element = await mount(entry({ title: '(untitled)', excerpt: 'Watch on Bluesky' }));

    expect(element.querySelector('.caption')).toBeNull();
  });

  it('keeps a single picture at its own aspect ratio', async () => {
    const element = await mount(entry({ media: [image(1, 1020, 1180)] }));
    const frame = element.querySelector<HTMLElement>('.media')!;

    expect(frame.dataset['count']).toBe('1');
    expect(frame.style.getPropertyValue('--ratio')).toBe(String(1020 / 1180));
    expect(frame.querySelector('img')!.getAttribute('src')).toBe('https://cdn.test/1.jpg');
  });

  it('lays out several pictures as a grid of their count', async () => {
    const element = await mount(entry({ media: [image(1), image(2), image(3)] }));
    const frame = element.querySelector<HTMLElement>('.media')!;

    expect(frame.dataset['count']).toBe('3');
    expect(frame.querySelectorAll('img')).toHaveLength(3);
  });

  it('plays a video inline from its poster, streaming it through hls.js', async () => {
    const element = await mount(entry({ media: [clip] }));
    const video = element.querySelector('video')!;

    expect(video.getAttribute('src')).toBe('https://video.test/playlist.m3u8');
    expect(video.getAttribute('poster')).toBe('https://video.test/thumb.jpg');
    expect(video.getAttribute('preload')).toBe('none');
    expect(video.hasAttribute('controls')).toBe(true);
    expect(video.hasAttribute('playsinline')).toBe(true);
    expect(isHlsManaged(video)).toBe(true);
  });

  it('keeps a click on the video for its controls, and opens the post from a picture', async () => {
    const element = await mount(entry({ media: [clip, image(2)] }));

    element.querySelector('video')!.click();
    expect(entryActions.open).not.toHaveBeenCalled();

    element.querySelector<HTMLElement>('img')!.click();
    expect(entryActions.open).toHaveBeenCalledTimes(1);
  });

  it('keeps Enter and Space on the video for its controls, and opens the post from the article', async () => {
    const element = await mount(entry({ media: [clip] }));

    press(element.querySelector('video')!, 'Enter');
    press(element.querySelector('video')!, ' ');
    expect(entryActions.open).not.toHaveBeenCalled();

    press(element.querySelector('article')!, 'Enter');
    expect(entryActions.open).toHaveBeenCalledTimes(1);
  });

  it('names the video by its caption, or generically without one', async () => {
    const captioned = await mount(entry({ media: [clip] }));
    expect(captioned.querySelector('video')!.getAttribute('aria-label')).toBe(
      'A sunrise with Nutmeg the donkey',
    );
    TestBed.resetTestingModule();

    const bare = await mount(entry({ media: [clip], title: '(untitled)' }));
    expect(bare.querySelector('video')!.getAttribute('aria-label')).toBe('Video');
  });

  it('pauses its videos when destroyed', async () => {
    const fixture = await mountFixture(entry({ media: [clip] }));

    fixture.destroy();

    expect(pause).toHaveBeenCalledTimes(1);
  });

  it('pauses a video that scrolls out of view', async () => {
    const callbacks: IntersectionObserverCallback[] = [];
    const real = globalThis.IntersectionObserver;
    globalThis.IntersectionObserver = class {
      constructor(callback: IntersectionObserverCallback) {
        callbacks.push(callback);
      }
      readonly observe = jest.fn();
      readonly disconnect = jest.fn();
    } as unknown as typeof IntersectionObserver;
    try {
      const element = await mount(entry({ media: [clip] }));
      const video = element.querySelector('video')!;

      callbacks[0]([sighting(video, true)], {} as IntersectionObserver);
      expect(pause).not.toHaveBeenCalled();
      callbacks[0]([sighting(video, false)], {} as IntersectionObserver);
      expect(pause).toHaveBeenCalledTimes(1);
    } finally {
      globalThis.IntersectionObserver = real;
    }
  });
});
