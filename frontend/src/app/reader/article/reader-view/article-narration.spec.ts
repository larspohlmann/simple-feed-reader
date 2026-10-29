import { TestBed } from '@angular/core/testing';
import { injectIslands } from '../../../../testing/article-islands-testing';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';
import { articleHost } from '../../../../testing/article-host-testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ArticleIslands } from './article-islands';
import { ArticleNarration } from './article-narration';

const NARRATION = '<audio class="reader-narration" src="https://x.test/full.mp3"></audio>';

describe('ArticleNarration', () => {
  let islands: ArticleIslands;
  let narration: ArticleNarration;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [provideAccountIdentity()],
    });
    islands = injectIslands();
    narration = new ArticleNarration(islands);
  });

  it('puts a toggle right before a narration player', () => {
    const host = articleHost(NARRATION);

    narration.mount(host);

    expect(host.querySelector('app-narration-toggle + audio.reader-narration')).not.toBeNull();
  });

  it('leaves an ordinary audio element alone', () => {
    const host = articleHost('<audio src="https://x.test/a.mp3"></audio>');

    narration.mount(host);

    expect(host.querySelector('app-narration-toggle')).toBeNull();
  });

  it('keeps the audio in the body when mounted again over the same DOM', () => {
    const host = articleHost(NARRATION);
    const player = host.querySelector('audio')!;
    narration.mount(host);

    islands.releaseAll();
    narration.mount(host);

    expect(host.querySelector('audio')).toBe(player);
    expect(host.querySelectorAll('app-narration-toggle')).toHaveLength(1);
    expect(host.querySelectorAll('audio.reader-narration')).toHaveLength(1);
  });
});
