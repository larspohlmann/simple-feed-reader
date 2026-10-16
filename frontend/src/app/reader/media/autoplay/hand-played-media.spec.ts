import { PlayingMedium } from './autoplay-player';
import { HandPlayedMedia } from './hand-played-media';

describe('HandPlayedMedia', () => {
  let coordinator: { nowPlaying: jest.Mock; stopped: jest.Mock };
  let media: HandPlayedMedia;
  let root: HTMLElement;

  beforeEach(() => {
    coordinator = { nowPlaying: jest.fn(), stopped: jest.fn() };
    media = new HandPlayedMedia(coordinator);
    root = document.createElement('div');
    root.innerHTML = '<video></video><audio></audio>';
  });

  const video = (): HTMLVideoElement => root.querySelector('video')!;
  const audio = (): HTMLAudioElement => root.querySelector('audio')!;

  function playing(medium: HTMLMediaElement): void {
    Object.defineProperty(medium, 'paused', { configurable: true, value: false });
  }

  function reported(mock: jest.Mock): Element[] {
    return mock.mock.calls.map(([medium]: [PlayingMedium]) => medium.element);
  }

  it('tells the coordinator when a video or an audio starts playing', () => {
    media.follow(root);
    playing(video());
    playing(audio());

    video().dispatchEvent(new Event('play'));
    audio().dispatchEvent(new Event('play'));

    expect(reported(coordinator.nowPlaying)).toEqual([video(), audio()]);
  });

  it('ignores a play of a medium paused again before the event arrives', () => {
    media.follow(root);

    video().dispatchEvent(new Event('play'));

    expect(coordinator.nowPlaying).not.toHaveBeenCalled();
  });

  it('tells the coordinator when a medium pauses or ends', () => {
    media.follow(root);

    video().dispatchEvent(new Event('pause'));
    audio().dispatchEvent(new Event('ended'));

    expect(reported(coordinator.stopped)).toEqual([video(), audio()]);
  });

  it('pauses the medium the coordinator stops', () => {
    media.follow(root);
    playing(video());
    const pause = jest.spyOn(video(), 'pause').mockImplementation(() => undefined);
    video().dispatchEvent(new Event('play'));

    (coordinator.nowPlaying.mock.calls[0][0] as PlayingMedium).stop();

    expect(pause).toHaveBeenCalledTimes(1);
  });

  it('follows each medium once however often it is asked', () => {
    media.follow(root);
    media.follow(root);
    playing(video());

    video().dispatchEvent(new Event('play'));

    expect(coordinator.nowPlaying).toHaveBeenCalledTimes(1);
  });

  it('forgets a medium that left the root, reporting it stopped', () => {
    media.follow(root);
    const gone = video();
    gone.remove();

    media.follow(root);
    playing(gone);
    gone.dispatchEvent(new Event('play'));

    expect(coordinator.nowPlaying).not.toHaveBeenCalled();
    expect(reported(coordinator.stopped)).toEqual([gone]);
  });

  it('forgets every medium at once, reporting each stopped', () => {
    media.follow(root);

    media.forgetAll();
    playing(video());
    video().dispatchEvent(new Event('play'));
    video().dispatchEvent(new Event('pause'));

    expect(coordinator.nowPlaying).not.toHaveBeenCalled();
    expect(reported(coordinator.stopped)).toEqual([video(), audio()]);
  });
});
