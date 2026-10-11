import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { articleHost } from '../../../../testing/article-host-testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { decorateArticle } from './decorate-article';

const decorated = (html: string): HTMLElement =>
  articleHost(html, (host) => decorateArticle(host, TestBed.inject(TranslocoService), null));

describe('decorateArticle', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] });
  });

  it('puts the landscape embed the media step builds from a link inside a cinema box', () => {
    const host = decorated('<a href="https://www.youtube-nocookie.com/embed/GhUuOxrCato">x</a>');

    expect(host.querySelector('.reader-cinema > .reader-embed iframe')).not.toBeNull();
  });

  it('leaves the portrait embed the media step builds from a Short outside a cinema box', () => {
    const host = decorated(
      '<a href="https://www.youtube-nocookie.com/embed/GhUuOxrCato#shorts">x</a>',
    );

    expect(host.querySelector('.reader-embed iframe')).not.toBeNull();
    expect(host.querySelector('.reader-cinema')).toBeNull();
  });

  it('keeps the narration box a collapsed player while a publisher disclosure becomes a FAQ', () => {
    const host = decorated(
      '<audio class="reader-narration" src="https://x.test/full.mp3"></audio>' +
        '<details><summary>Question?</summary><p>Answer.</p></details>',
    );
    const [narration, disclosure] = Array.from(host.querySelectorAll('details'));

    expect(narration.querySelector('audio')).not.toBeNull();
    expect(narration.classList.contains('reader-faq')).toBe(false);
    expect(narration.open).toBe(false);
    expect(disclosure.classList.contains('reader-faq')).toBe(true);
    expect(disclosure.open).toBe(true);
  });
});
