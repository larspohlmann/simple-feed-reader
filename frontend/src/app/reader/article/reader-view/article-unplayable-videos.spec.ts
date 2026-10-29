import { Injector } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';
import { articleHost } from '../../../../testing/article-host-testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ArticleIslands } from './article-islands';
import { ArticleUnplayableVideos } from './article-unplayable-videos';

const PAGE = 'https://news.test/story';

describe('ArticleUnplayableVideos', () => {
  let islands: ArticleIslands;
  let videos: ArticleUnplayableVideos;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [provideAccountIdentity()],
    });
    islands = new ArticleIslands(TestBed.inject(Injector));
    videos = new ArticleUnplayableVideos(islands, TestBed.inject(TranslocoService));
  });

  it('puts a link card to the original page before a failed video, which stays hidden behind it', () => {
    const element = articleHost(
      '<video src="https://cdn.test/clip.mp4" poster="https://cdn.test/p.jpg">',
    );
    const video = element.querySelector('video')!;

    videos.fail(video, PAGE);

    expect(video.classList.contains('reader-video--unplayable')).toBe(true);
    const card = video.previousElementSibling!;
    expect(card.tagName).toBe('APP-LINK-CARD');
    expect(card.classList.contains('reader-island')).toBe(true);
    const link = card.querySelector<HTMLAnchorElement>('a')!;
    expect(link.href).toBe('https://news.test/story');
    expect(link.target).toBe('_blank');
    expect(link.rel).toBe('noopener noreferrer');
    expect(link.querySelector('img')!.getAttribute('src')).toBe('https://cdn.test/p.jpg');
    expect(link.querySelector('strong')!.textContent).toBe("This video can't play in your browser");
    expect(link.querySelector('span')!.textContent).toBe('Watch it on the original page');
    expect(link.querySelector('small')!.textContent).toBe('news.test');
  });

  it('pauses a failed video, so it gives up its turn in the playback coordinator', () => {
    const element = articleHost('<video src="https://cdn.test/clip.mp4"></video>');
    const video = element.querySelector('video')!;
    const pause = jest.fn();
    video.pause = pause;

    videos.fail(video, PAGE);

    expect(pause).toHaveBeenCalledTimes(1);
  });

  it('links the video file itself when the entry has no page', () => {
    const element = articleHost('<video src="https://cdn.test/clip.mp4"></video>');

    videos.fail(element.querySelector('video'), null);

    const link = element.querySelector<HTMLAnchorElement>('app-link-card a')!;
    expect(link.href).toBe('https://cdn.test/clip.mp4');
    expect(link.querySelector('img')).toBeNull();
    expect(link.querySelector('small')!.textContent).toBe('cdn.test');
  });

  it('marks the video once its last source fails, and not before', () => {
    const element = articleHost(
      '<video><source src="https://cdn.test/a.webm"><source src="https://cdn.test/b.mp4"></video>',
    );
    const [first, last] = Array.from(element.querySelectorAll('source'));
    const video = element.querySelector('video')!;

    videos.fail(first, PAGE);
    expect(element.querySelector('app-link-card')).toBeNull();
    expect(video.className).toBe('');

    videos.fail(last, PAGE);
    expect(element.querySelector('app-link-card')).not.toBeNull();
    expect(video.classList.contains('reader-video--unplayable')).toBe(true);
  });

  it('links the first source when neither a page nor a src is known', () => {
    const element = articleHost('<video><source src="https://cdn.test/a.mp4"></video>');

    videos.fail(element.querySelector('source'), null);

    expect(element.querySelector<HTMLAnchorElement>('app-link-card a')!.href).toBe(
      'https://cdn.test/a.mp4',
    );
  });

  it('leaves a playlist video to hls.js, which swaps its source on first play', () => {
    const element = articleHost('<video src="https://cdn.test/master.m3u8"></video>');
    const video = element.querySelector('video')!;

    videos.fail(video, PAGE);

    expect(element.querySelector('app-link-card')).toBeNull();
    expect(video.className).toBe('');
  });

  it('ignores errors that are not a video failing', () => {
    const element = articleHost('<img src="https://cdn.test/broken.jpg">');

    videos.fail(element.querySelector('img'), PAGE);

    expect(element.querySelector('img')).not.toBeNull();
    expect(element.querySelector('app-link-card')).toBeNull();
  });

  it('mounts no second card when the same video fails again', () => {
    const element = articleHost('<video src="https://cdn.test/clip.mp4"></video>');
    const video = element.querySelector('video')!;

    videos.fail(video, PAGE);
    videos.fail(video, PAGE);

    expect(element.querySelectorAll('app-link-card')).toHaveLength(1);
  });

  it('brings the card back after a re-render released the islands', () => {
    const element = articleHost('<video src="https://cdn.test/clip.mp4"></video>');
    const video = element.querySelector('video')!;
    videos.fail(video, PAGE);

    islands.releaseAll();

    expect(element.querySelector('app-link-card')).toBeNull();
    expect(video.classList.contains('reader-video--unplayable')).toBe(true);

    videos.mount(element, PAGE);

    expect(element.querySelectorAll('app-link-card')).toHaveLength(1);
    expect(video.previousElementSibling!.tagName).toBe('APP-LINK-CARD');
  });

  it('mounts nothing for a body without a marked video', () => {
    const element = articleHost('<video src="https://cdn.test/clip.mp4"></video>');

    videos.mount(element, PAGE);

    expect(element.querySelector('app-link-card')).toBeNull();
  });
});
