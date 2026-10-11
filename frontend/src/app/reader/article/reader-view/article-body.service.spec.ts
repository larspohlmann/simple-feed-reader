import { ElementRef, EnvironmentInjector, createEnvironmentInjector, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { articleHost } from '../../../../testing/article-host-testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ImageProxyService } from '../../../shared/proxied-image/image-proxy.service';
import { ArticleBody } from './article-body.service';

const VIDEO = '<video controls src="https://x.test/clip.mp4"></video>';

describe('ArticleBody', () => {
  let recover: jest.Mock;
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

  const pressT = (): void => {
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 't', cancelable: true }));
  };
  const cinemaToggleClicks = (): jest.SpyInstance =>
    jest
      .spyOn(content.querySelector<HTMLButtonElement>('.reader-cinema__toggle')!, 'click')
      .mockImplementation(() => undefined);

  beforeEach(() => {
    recover = jest.fn().mockResolvedValue('failed');
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [{ provide: ImageProxyService, useValue: { recover } }],
    });
    injector = createEnvironmentInjector([ArticleBody], TestBed.inject(EnvironmentInjector));
  });

  afterEach(() => {
    if (!injector.destroyed) injector.destroy();
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

    expect(recover).toHaveBeenCalledWith(image);
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
    expect(recover).not.toHaveBeenCalled();
  });
});
