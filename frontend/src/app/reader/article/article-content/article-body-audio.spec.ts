import { TestBed } from '@angular/core/testing';
import { injectIslands } from '../../../../testing/article-islands-testing';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';
import { articleHost } from '../../../../testing/article-host-testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { anEntry } from '../../../../testing/entry-testing';
import { StubAudioPlayer } from '../../../../testing/stub-audio-player';
import { StubListenLauncher } from '../../../../testing/stub-listen-launcher';
import { embedTrackOrigin } from '../../audio/embed-track';
import { ArticleIslands } from './article-islands';
import { ArticleBodyAudio } from './article-body-audio';

const ORIGIN = embedTrackOrigin(anEntry({ id: 7, title: 'The article' }));
const CLIP =
  '<audio controls src="https://x.test/a.mp3" title="Clip" data-duration="2871"></audio>';

describe('ArticleBodyAudio', () => {
  let islands: ArticleIslands;
  let player: StubAudioPlayer;
  let bodyAudio: ArticleBodyAudio;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [provideAccountIdentity()],
    });
    islands = injectIslands();
    player = new StubAudioPlayer();
    bodyAudio = new ArticleBodyAudio(
      islands,
      player.asService(),
      new StubListenLauncher(player).asService(),
    );
  });

  it('puts the Listen row in place of a body audio player', () => {
    const host = articleHost(CLIP);

    bodyAudio.mount(host, ORIGIN);

    expect(host.querySelector('app-listen-row + audio.reader-audio--replaced')).not.toBeNull();
  });

  it('plays the clip from the row', () => {
    const host = articleHost(CLIP);
    bodyAudio.mount(host, ORIGIN);

    host.querySelector<HTMLButtonElement>('app-listen-row button.listen')!.click();

    expect(player.played.map((track) => track.title)).toEqual(['Clip']);
  });

  it('shows the clip length on the row', () => {
    const host = articleHost(CLIP);

    bodyAudio.mount(host, ORIGIN);

    expect(host.querySelector('.listen-duration')?.textContent).toBe('47:51');
  });

  it('leaves narration to its own toggle', () => {
    const host = articleHost(
      '<audio class="reader-narration" src="https://x.test/full.mp3"></audio>',
    );

    bodyAudio.mount(host, ORIGIN);

    expect(host.querySelector('app-listen-row')).toBeNull();
  });

  it('leaves a player with nothing to play, and every player without an article to name it', () => {
    const silent = articleHost('<audio controls></audio>');
    const orphan = articleHost(CLIP);

    bodyAudio.mount(silent, ORIGIN);
    bodyAudio.mount(orphan, null);

    expect(silent.querySelector('app-listen-row')).toBeNull();
    expect(orphan.querySelector('app-listen-row')).toBeNull();
    expect(orphan.querySelector('audio.reader-audio--replaced')).toBeNull();
  });

  it('mounts one row again over the same DOM', () => {
    const host = articleHost(CLIP);
    bodyAudio.mount(host, ORIGIN);

    islands.releaseAll();
    bodyAudio.mount(host, ORIGIN);

    expect(host.querySelectorAll('app-listen-row')).toHaveLength(1);
    expect(host.querySelectorAll('audio')).toHaveLength(1);
  });
});
