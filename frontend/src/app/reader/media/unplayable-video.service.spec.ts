import { TestBed } from '@angular/core/testing';
import { articleHost } from '../../../testing/article-host-testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { UnplayableVideoService } from './unplayable-video.service';

const PAGE = 'https://news.test/story';

describe('UnplayableVideoService', () => {
  let videos: UnplayableVideoService;

  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] });
    videos = TestBed.inject(UnplayableVideoService);
  });

  it('replaces a failed video with a link card to the original page', () => {
    const element = articleHost(
      '<video src="https://cdn.test/clip.mp4" poster="https://cdn.test/p.jpg">',
    );

    videos.replace(element.querySelector('video'), PAGE);

    expect(element.querySelector('video')).toBeNull();
    const link = element.querySelector<HTMLAnchorElement>('figure.link-card > a')!;
    expect(link.href).toBe('https://news.test/story');
    expect(link.target).toBe('_blank');
    expect(link.rel).toBe('noopener noreferrer');
    expect(link.querySelector('img')!.getAttribute('src')).toBe('https://cdn.test/p.jpg');
    expect(link.querySelector('strong')!.textContent).toBe("This video can't play in your browser");
    expect(link.querySelector('span')!.textContent).toBe('Watch it on the original page');
    expect(link.querySelector('small')!.textContent).toBe('news.test');
  });

  it('links the video file itself when the entry has no page', () => {
    const element = articleHost('<video src="https://cdn.test/clip.mp4"></video>');

    videos.replace(element.querySelector('video'), null);

    const link = element.querySelector<HTMLAnchorElement>('.link-card a')!;
    expect(link.href).toBe('https://cdn.test/clip.mp4');
    expect(link.querySelector('img')).toBeNull();
    expect(link.querySelector('small')!.textContent).toBe('cdn.test');
  });

  it('replaces the video once its last source fails, and not before', () => {
    const element = articleHost(
      '<video><source src="https://cdn.test/a.webm"><source src="https://cdn.test/b.mp4"></video>',
    );
    const [first, last] = Array.from(element.querySelectorAll('source'));

    videos.replace(first, PAGE);
    expect(element.querySelector('video')).not.toBeNull();

    videos.replace(last, PAGE);
    expect(element.querySelector('video')).toBeNull();
    expect(element.querySelector('.link-card')).not.toBeNull();
  });

  it('links the first source when neither a page nor a src is known', () => {
    const element = articleHost('<video><source src="https://cdn.test/a.mp4"></video>');

    videos.replace(element.querySelector('source'), null);

    expect(element.querySelector<HTMLAnchorElement>('.link-card a')!.href).toBe(
      'https://cdn.test/a.mp4',
    );
  });

  it('leaves a playlist video to hls.js, which swaps its source on first play', () => {
    const element = articleHost('<video src="https://cdn.test/master.m3u8"></video>');

    videos.replace(element.querySelector('video'), PAGE);

    expect(element.querySelector('video')).not.toBeNull();
  });

  it('ignores errors that are not a video failing', () => {
    const element = articleHost('<img src="https://cdn.test/broken.jpg">');

    videos.replace(element.querySelector('img'), PAGE);

    expect(element.querySelector('img')).not.toBeNull();
    expect(element.querySelector('.link-card')).toBeNull();
  });
});
