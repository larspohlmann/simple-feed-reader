import { ElementRef, EnvironmentInjector, createEnvironmentInjector, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { articleHost } from '../../../../testing/article-host-testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ImageProxyService } from '../../../shared/proxied-image/image-proxy.service';
import { neverRecoveringImageProxy } from '../../../../testing/image-proxy-testing';
import { fakeHls, firstPlay, resetFakeHls } from '../../../../testing/hls-testing';
import { ArticleBody } from './article-body.service';

jest.mock('hls.js', () => jest.requireActual('../../../../testing/hls-testing').fakeHlsModule);

const VIDEO = '<video controls src="https://x.test/clip.mp4"></video>';
const STREAM = '<video controls src="https://x.test/master.m3u8"></video>';

describe('ArticleBody', () => {
  let imageProxy: ReturnType<typeof neverRecoveringImageProxy>;
  let injector: EnvironmentInjector;
  let decorated: jest.Mock<void, [HTMLElement]>;
  let content: HTMLElement;
  const html = signal('');

  function connectBody(body: string): void {
    content = articleHost(body);
    html.set(body);
    decorated = jest.fn();
    injector.get(ArticleBody).connect({
      content: signal(new ElementRef(content)),
      html,
      pageUrl: () => 'https://news.test/story',
      enclosureUrl: () => null,
      decorated,
    });
    TestBed.tick();
  }

  async function playBodyStream(): Promise<void> {
    connectBody(STREAM);
    document.body.append(content);
    await Promise.resolve();
    await firstPlay(content.querySelector('video')!);
  }

  const pressT = (): void => {
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 't', cancelable: true }));
  };
  const cinemaToggleClicks = (): jest.SpyInstance =>
    jest
      .spyOn(content.querySelector<HTMLButtonElement>('.reader-cinema__toggle')!, 'click')
      .mockImplementation(() => undefined);

  beforeEach(() => {
    imageProxy = neverRecoveringImageProxy();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [{ provide: ImageProxyService, useValue: imageProxy }],
    });
    injector = createEnvironmentInjector([ArticleBody], TestBed.inject(EnvironmentInjector));
  });

  afterEach(() => {
    if (!injector.destroyed) injector.destroy();
    document.body.innerHTML = '';
  });

  it('decorates the body once it renders, then hands it on', async () => {
    connectBody('<a href="https://ext.test/z">ext</a>');
    expect(decorated).not.toHaveBeenCalled();

    await Promise.resolve();

    expect(content.querySelector('a')!.target).toBe('_blank');
    expect(decorated).toHaveBeenCalledWith(content);
  });

  it('decorates again when the rendered HTML changes', async () => {
    connectBody('<p>Feed.</p>');
    await Promise.resolve();

    html.set('<p>Reader.</p>');
    TestBed.tick();
    await Promise.resolve();

    expect(decorated).toHaveBeenCalledTimes(2);
  });

  it('hands a failed body image to the proxy', () => {
    connectBody('<img src="https://www.oxmoxhh.de/cover.png" alt="">');
    const image = content.querySelector('img')!;

    image.dispatchEvent(new Event('error'));

    expect(imageProxy.attempts).toEqual([image]);
  });

  it('replaces a body video the browser cannot play with a link to the original page', () => {
    connectBody(VIDEO);

    content.querySelector('video')!.dispatchEvent(new Event('error'));

    expect(content.querySelector('video')).toBeNull();
    const link = content.querySelector<HTMLAnchorElement>('figure.link-card a')!;
    expect(link.href).toBe('https://news.test/story');
    expect(link.querySelector('strong')!.textContent).toBe("This video can't play in your browser");
  });

  it('widens the video on the t key', async () => {
    connectBody(VIDEO);
    await Promise.resolve();
    const clicks = cinemaToggleClicks();

    pressT();

    expect(clicks).toHaveBeenCalled();
  });

  it('lets go of the body and the document once destroyed', async () => {
    connectBody(VIDEO);
    await Promise.resolve();
    const clicks = cinemaToggleClicks();
    const image = document.createElement('img');
    content.append(image);

    injector.destroy();
    pressT();
    image.dispatchEvent(new Event('error'));

    expect(clicks).not.toHaveBeenCalled();
    expect(imageProxy.attempts).toEqual([]);
  });

  it('keeps a playing stream while the body stays, and releases it when the HTML changes', async () => {
    resetFakeHls();
    await playBodyStream();

    TestBed.tick();
    expect(fakeHls.destroy).not.toHaveBeenCalled();

    html.set('<p>Reader.</p>');
    TestBed.tick();
    expect(fakeHls.destroy).toHaveBeenCalledTimes(1);
  });

  it('releases the body stream once destroyed', async () => {
    resetFakeHls();
    await playBodyStream();

    injector.destroy();

    expect(fakeHls.destroy).toHaveBeenCalledTimes(1);
  });
});
