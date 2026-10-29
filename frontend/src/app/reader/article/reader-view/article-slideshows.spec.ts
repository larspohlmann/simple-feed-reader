import { TestBed } from '@angular/core/testing';
import { injectIslands } from '../../../../testing/article-islands-testing';
import { articleHost } from '../../../../testing/article-host-testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ArticleIslands } from './article-islands';
import { ArticleSlideshows } from './article-slideshows';

const THREE =
  '<figure class="reader-slideshow"><ol>' +
  '<li><img src="https://img/1.jpg" alt="one"></li>' +
  '<li><img src="https://img/2.jpg" alt="two"></li>' +
  '<li><img src="https://img/3.jpg" alt="three"></li></ol></figure>';
const ONE =
  '<figure class="reader-slideshow"><ol><li><img src="https://img/1.jpg" alt="one"></li></ol></figure>';

describe('ArticleSlideshows', () => {
  let islands: ArticleIslands;
  let slideshows: ArticleSlideshows;

  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] });
    islands = injectIslands();
    slideshows = new ArticleSlideshows(islands);
  });

  it('puts controls right after the track of a multi-slide figure and marks it ready', () => {
    const host = articleHost(THREE);

    slideshows.mount(host);
    TestBed.tick();

    expect(host.querySelectorAll('app-slideshow-controls').length).toBe(1);
    expect(host.querySelector('ol + app-slideshow-controls')).not.toBeNull();
    expect(
      host.querySelector('.reader-slideshow')!.classList.contains('reader-slideshow--ready'),
    ).toBe(true);
  });

  it('leaves a single-slide figure alone', () => {
    const host = articleHost(ONE);

    slideshows.mount(host);
    TestBed.tick();

    const figure = host.querySelector<HTMLElement>('.reader-slideshow')!;
    expect(figure.classList.contains('reader-slideshow--ready')).toBe(false);
    expect(host.querySelector('app-slideshow-controls')).toBeNull();
  });

  it('keeps the slides in the figure when mounted again over the same DOM', () => {
    const host = articleHost(THREE);
    slideshows.mount(host);
    TestBed.tick();

    islands.releaseAll();
    slideshows.mount(host);
    TestBed.tick();

    const figure = host.querySelector<HTMLElement>('.reader-slideshow')!;
    expect(figure.querySelectorAll('li').length).toBe(3);
    expect(figure.querySelectorAll('app-slideshow-controls').length).toBe(1);
  });
});
