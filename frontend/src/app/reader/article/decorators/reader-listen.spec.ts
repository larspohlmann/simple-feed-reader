import { articleHost } from '../../../../testing/article-host-testing';
import { upgradeMediaEmbeds, withScriptablePlayer } from '../../media/media-embeds';
import { addCinemaToggles } from './reader-cinema';
import {
  addEmbedListenControls,
  type EmbedListenControl,
  type ListenActions,
  type ListenLabels,
} from './reader-listen';

const VIDEO_ID = 'dQw4w9WgXcQ';
const WIDGET_URL =
  'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F123456789';
const YOUTUBE = `<a href="https://www.youtube-nocookie.com/embed/${VIDEO_ID}">Video</a>`;
const SHORT = `<a href="https://www.youtube-nocookie.com/embed/${VIDEO_ID}#shorts">Short</a>`;
const SOUNDCLOUD = `<a href="${WIDGET_URL}">Track</a>`;
const VIMEO = '<a href="https://player.vimeo.com/video/123456">Vimeo</a>';
const NATIVE_VIDEO = '<video src="https://cdn/clip.mp4"></video>';

const labels: ListenLabels = {
  listen: 'Listen',
  listenEmbed: 'Play the sound in the player',
  addToPlaylist: 'Add to playlist',
  inPlaylist: 'In playlist',
};

function actionsSpy(): ListenActions {
  return { listen: jest.fn(), toggleQueued: jest.fn() };
}

function playerHost(html: string): HTMLElement {
  return articleHost(html, (host) => {
    upgradeMediaEmbeds(host, (url) => withScriptablePlayer(url, 'https://reader.test'));
    addCinemaToggles(host, { enter: 'Cinema mode', exit: 'Exit cinema mode' });
  });
}

function decorated(html: string, actions = actionsSpy()): HTMLElement {
  const host = playerHost(html);
  addEmbedListenControls(host, labels, actions);
  return host;
}

const buttonTexts = (bar: Element): string[] =>
  Array.from(bar.querySelectorAll('button'), (button) => button.textContent ?? '');

const listenButton = (host: HTMLElement): HTMLButtonElement =>
  host.querySelector<HTMLButtonElement>('.reader-listen__button')!;

const queueToggle = (host: HTMLElement): HTMLButtonElement =>
  host.querySelector<HTMLButtonElement>('.reader-listen__button[aria-pressed]')!;

describe('addEmbedListenControls', () => {
  it('puts Listen and the queue toggle before the cinema toggle of a landscape YouTube video', () => {
    const host = decorated(YOUTUBE);

    const bar = host.querySelector('.reader-cinema > .reader-player-bar')!;
    expect(bar.classList.contains('reader-listen')).toBe(true);
    expect(buttonTexts(bar)).toEqual([
      'play_circleListen',
      'playlist_addAdd to playlist',
      'width_wideCinema mode(t)',
    ]);
    expect(host.querySelectorAll('.reader-player-bar')).toHaveLength(1);
  });

  it('builds the buttons like the cinema toggle', () => {
    const host = decorated(YOUTUBE);
    const listen = listenButton(host);
    const queue = queueToggle(host);

    expect(listen.type).toBe('button');
    expect(listen.title).toBe('Play the sound in the player');
    expect(listen.querySelector('.material-symbols-outlined')?.getAttribute('aria-hidden')).toBe(
      'true',
    );
    expect(queue.type).toBe('button');
    expect(queue.getAttribute('aria-pressed')).toBe('false');
    expect(queue.classList.contains('reader-listen__button--queued')).toBe(false);
  });

  it('gives a SoundCloud box a player bar of its own, right after the box', () => {
    const host = decorated(SOUNDCLOUD);

    const box = host.querySelector('.reader-embed--audio')!;
    const bar = box.nextElementSibling!;
    expect(bar.classList.contains('reader-player-bar')).toBe(true);
    expect(bar.classList.contains('reader-listen')).toBe(true);
    expect(buttonTexts(bar)).toEqual(['play_circleListen', 'playlist_addAdd to playlist']);
    expect(host.querySelector('.reader-cinema')).toBeNull();
  });

  it.each([
    ['a Short', SHORT],
    ['a Vimeo frame', VIMEO],
    ['a native video', NATIVE_VIDEO],
  ])('adds nothing under %s', (_shape, html) => {
    const host = playerHost(html);

    const controls = addEmbedListenControls(host, labels, actionsSpy());

    expect(controls).toEqual([]);
    expect(host.querySelector('.reader-listen__button')).toBeNull();
    expect(host.querySelector('.reader-listen')).toBeNull();
  });

  it('reports one control per supported embed, carrying its media', () => {
    const host = playerHost(YOUTUBE + VIMEO + SOUNDCLOUD);

    const controls = addEmbedListenControls(host, labels, actionsSpy());

    expect(controls.map((control) => control.media)).toEqual([
      {
        kind: 'youtube',
        url: `https://www.youtube.com/watch?v=${VIDEO_ID}`,
        posterUrl: `https://i.ytimg.com/vi/${VIDEO_ID}/hqdefault.jpg`,
      },
      { kind: 'soundcloud', url: WIDGET_URL, posterUrl: null },
    ]);
  });

  it('hands Listen the media and the frame that shows it', () => {
    const actions = actionsSpy();
    const host = decorated(YOUTUBE, actions);

    listenButton(host).click();

    expect(actions.listen).toHaveBeenCalledWith(
      expect.objectContaining({ url: `https://www.youtube.com/watch?v=${VIDEO_ID}` }),
      host.querySelector('iframe'),
    );
    expect(actions.toggleQueued).not.toHaveBeenCalled();
  });

  it('hands the queue toggle the media', () => {
    const actions = actionsSpy();
    const host = decorated(SOUNDCLOUD, actions);

    queueToggle(host).click();

    expect(actions.toggleQueued).toHaveBeenCalledWith({
      kind: 'soundcloud',
      url: WIDGET_URL,
      posterUrl: null,
    });
    expect(actions.listen).not.toHaveBeenCalled();
  });

  it('shows the queue state through icon, label, pressed state and class', () => {
    const host = playerHost(YOUTUBE);
    const [control] = addEmbedListenControls(host, labels, actionsSpy()) as [EmbedListenControl];
    const queue = queueToggle(host);

    control.showQueued(true);
    expect(queue.textContent).toBe('playlist_add_checkIn playlist');
    expect(queue.getAttribute('aria-pressed')).toBe('true');
    expect(queue.classList.contains('reader-listen__button--queued')).toBe(true);

    control.showQueued(false);
    expect(queue.textContent).toBe('playlist_addAdd to playlist');
    expect(queue.getAttribute('aria-pressed')).toBe('false');
    expect(queue.classList.contains('reader-listen__button--queued')).toBe(false);
  });

  it('is idempotent', () => {
    const host = decorated(YOUTUBE + SOUNDCLOUD);

    const controls = addEmbedListenControls(host, labels, actionsSpy());

    expect(controls).toEqual([]);
    expect(host.querySelectorAll('.reader-player-bar')).toHaveLength(2);
    expect(host.querySelectorAll('.reader-listen__button')).toHaveLength(4);
  });
});
