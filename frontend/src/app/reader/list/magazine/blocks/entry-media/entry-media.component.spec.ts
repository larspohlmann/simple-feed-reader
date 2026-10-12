import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../../../testing/transloco-testing';
import { EntryMediaComponent } from './entry-media.component';
import { EntryDto, EntryMediumDto } from '../../../../models';
import { EntryActionHandler } from '../../../../entry/entry-actions/entry-action-handler';
import { EMBED_FRAME_ATTRIBUTES } from '../../../../media/media-embeds';
import { MEDIA_PAUSE } from './pause-media';
import { anEntry } from '../../../../../../testing/entry-testing';
import { fakeHls, firstPlay, resetFakeHls } from '../../../../../../testing/hls-testing';

jest.mock(
  'hls.js',
  () => jest.requireActual('../../../../../../testing/hls-testing').fakeHlsModule,
);

let pause: jest.SpyInstance;

beforeEach(() => {
  resetFakeHls();
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

const entry = (over: Partial<EntryDto> = {}): EntryDto =>
  anEntry({
    url: 'https://bsky.app/profile/someone/post/1',
    excerpt: 'A sunrise with Nutmeg the donkey',
    media: [image(1)],
    titleDerived: true,
    share: 'media',
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

const COVER = 'https://i1.ytimg.com/vi/87Ov3cS-xMs/hqdefault.jpg';
const short = (over: Partial<EntryDto> = {}): EntryDto =>
  entry({
    title: 'Should Robert Pattinson join the DCU?',
    titleDerived: false,
    share: 'media',
    shortPlayerUrl: 'https://www.youtube-nocookie.com/embed/87Ov3cS-xMs#shorts',
    excerpt: '',
    url: 'https://www.youtube.com/shorts/87Ov3cS-xMs',
    imageUrl: COVER,
    imageWidth: 480,
    imageHeight: 360,
    imageAspectRatio: 0.5625,
    media: [{ url: COVER, kind: 'image', width: 480, height: 360 }],
    ...over,
  });

const sighting = (target: Element, isIntersecting: boolean) =>
  ({ target, isIntersecting }) as unknown as IntersectionObserverEntry;

const press = (target: Element, key: string) =>
  target.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));

let restoreObserver: (() => void) | null = null;

afterEach(() => {
  restoreObserver?.();
  restoreObserver = null;
});

function stubIntersectionObserver() {
  const callbacks: IntersectionObserverCallback[] = [];
  const observed: Element[] = [];
  const real = globalThis.IntersectionObserver;
  restoreObserver = () => (globalThis.IntersectionObserver = real);
  globalThis.IntersectionObserver = class {
    constructor(callback: IntersectionObserverCallback) {
      callbacks.push(callback);
    }
    readonly observe = (target: Element) => observed.push(target);
    readonly disconnect = jest.fn();
  } as unknown as typeof IntersectionObserver;
  const report = (target: Element, isIntersecting: boolean) =>
    callbacks[0]([sighting(target, isIntersecting)], {} as IntersectionObserver);
  return { observed, report };
}

describe('EntryMediaComponent', () => {
  it('captions a titled share with its title and puts its own text beneath', async () => {
    const element = await mount(
      entry({ titleDerived: false, title: 'Chart of the day', excerpt: 'From the report.' }),
    );

    expect(element.querySelector('.title')!.textContent).toContain('Chart of the day');
    expect(element.querySelector('.dek')!.textContent).toContain('From the report.');
  });

  it('shows the post text as the headline above the media', async () => {
    const element = await mount(entry());

    expect(element.querySelector('.title')!.textContent).toContain('A sunrise with Nutmeg');
  });

  it('shows no headline for a post without text', async () => {
    const element = await mount(entry({ title: '', excerpt: '' }));

    expect(element.querySelector('.title')).toBeNull();
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

    await firstPlay(video);

    expect(fakeHls.attachMedia).toHaveBeenCalledWith(video);
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

    const bare = await mount(entry({ media: [clip], title: '', excerpt: '' }));
    expect(bare.querySelector('video')!.getAttribute('aria-label')).toBe('Video');
  });

  it('pauses its videos when destroyed', async () => {
    const fixture = await mountFixture(entry({ media: [clip] }));

    fixture.destroy();

    expect(pause).toHaveBeenCalledTimes(1);
  });

  it('releases its played stream when destroyed', async () => {
    const fixture = await mountFixture(entry({ media: [clip] }));
    await firstPlay(fixture.nativeElement.querySelector('video'));

    fixture.destroy();

    expect(fakeHls.destroy).toHaveBeenCalledTimes(1);
  });

  it('pauses a video that scrolls out of view', async () => {
    const { report } = stubIntersectionObserver();
    const element = await mount(entry({ media: [clip] }));
    const video = element.querySelector('video')!;

    report(video, true);
    expect(pause).not.toHaveBeenCalled();
    report(video, false);
    expect(pause).toHaveBeenCalledTimes(1);
  });

  it('captions a Short with its title and frames its cover at the picture’s own shape', async () => {
    const element = await mount(short());

    expect(element.querySelector('.title')!.textContent).toContain('Should Robert Pattinson');
    expect(element.querySelector<HTMLElement>('.media')!.style.getPropertyValue('--ratio')).toBe(
      '0.5625',
    );
    expect(element.querySelector('iframe')).toBeNull();
  });

  it('plays a Short inline in the allow-listed player without opening the post', async () => {
    const fixture = await mountFixture(short());
    const element = fixture.nativeElement as HTMLElement;

    element.querySelector<HTMLButtonElement>('.play')!.click();
    fixture.detectChanges();

    const frame = element.querySelector('iframe')!;
    expect(frame.getAttribute('src')).toBe(
      'https://www.youtube-nocookie.com/embed/87Ov3cS-xMs?autoplay=1#shorts',
    );
    expect(frame.getAttribute('sandbox')).toBe(EMBED_FRAME_ATTRIBUTES.sandbox);
    expect(frame.getAttribute('referrerpolicy')).toBe(EMBED_FRAME_ATTRIBUTES.referrerpolicy);
    expect(frame.hasAttribute('allowfullscreen')).toBe(true);
    expect(frame.getAttribute('allow')).toBe('autoplay; fullscreen');
    expect(entryActions.open).not.toHaveBeenCalled();
  });

  it('sets a Short player’s src before the frame enters the document, so iOS keeps its referrer policy', async () => {
    const connectedAtSource: boolean[] = [];
    const setAttribute = Element.prototype.setAttribute;
    const sourceAttribute = jest
      .spyOn(Element.prototype, 'setAttribute')
      .mockImplementation(function (this: Element, name: string, value: string) {
        if (this instanceof HTMLIFrameElement && name === 'src')
          connectedAtSource.push(this.isConnected);
        setAttribute.call(this, name, value);
      });
    const fixture = await mountFixture(short());
    const element = fixture.nativeElement as HTMLElement;
    expect(element.isConnected).toBe(true);

    element.querySelector<HTMLButtonElement>('.play')!.click();
    fixture.detectChanges();
    sourceAttribute.mockRestore();

    expect(element.querySelector('iframe')).not.toBeNull();
    expect(connectedAtSource).not.toHaveLength(0);
    expect(connectedAtSource).not.toContain(true);
  });

  it('puts a Short’s player away when the list pauses its media', async () => {
    const fixture = await mountFixture(short());
    const element = fixture.nativeElement as HTMLElement;
    element.querySelector<HTMLButtonElement>('.play')!.click();
    fixture.detectChanges();

    element.dispatchEvent(new Event(MEDIA_PAUSE));
    fixture.detectChanges();

    expect(element.querySelector('iframe')).toBeNull();
    expect(element.querySelector('img')!.getAttribute('src')).toBe(COVER);
  });

  it('puts a Short’s player back to its cover when the card scrolls out of view', async () => {
    const { observed, report } = stubIntersectionObserver();
    const fixture = await mountFixture(short());
    const element = fixture.nativeElement as HTMLElement;
    element.querySelector<HTMLButtonElement>('.play')!.click();
    fixture.detectChanges();
    await fixture.whenStable();
    const frame = element.querySelector('.short-player')!;
    const pill = element.querySelector('.pill.short')!;
    expect(observed).toContain(frame);
    expect(observed).not.toContain(pill);

    report(frame, true);
    fixture.detectChanges();
    expect(element.querySelector('iframe')).not.toBeNull();

    report(frame, false);
    fixture.detectChanges();
    expect(element.querySelector('iframe')).toBeNull();
    expect(element.querySelector('img')!.getAttribute('src')).toBe(COVER);
  });

  it('shows the cover of a Short whose player is not allow-listed, with no play button', async () => {
    const element = await mount(
      short({ shortPlayerUrl: 'https://evil.test/embed/87Ov3cS-xMs#shorts' }),
    );

    expect(element.querySelector('.play')).toBeNull();
    expect(element.querySelector('img')!.getAttribute('src')).toBe(COVER);
  });

  it('counts a Short as one picture even when the post carries more media', async () => {
    const element = await mount(short({ media: [image(1), image(2), image(3)] }));

    expect(element.querySelector('.media')!.getAttribute('data-count')).toBe('1');
  });

  it('titles the player of an untitled Short by its play label', async () => {
    const fixture = await mountFixture(short({ title: '', titleDerived: true, excerpt: '' }));
    const element = fixture.nativeElement as HTMLElement;

    element.querySelector<HTMLButtonElement>('.play')!.click();
    fixture.detectChanges();

    const label = element.querySelector('iframe')!.getAttribute('title');
    expect(label).toBeTruthy();
    expect(label).not.toBe('');
  });
});
