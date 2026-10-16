import { VideoAutoplayPlayer } from './video-autoplay-player';

const refusal = () => new DOMException('autoplay with sound refused', 'NotAllowedError');
const settle = () => new Promise((resolve) => setTimeout(resolve));

function setUp() {
  const video = document.createElement('video');
  const play = jest.spyOn(video, 'play').mockResolvedValue(undefined);
  const pause = jest.spyOn(video, 'pause').mockImplementation(() => undefined);
  const coordinator = { nowPlaying: jest.fn(), soundChanged: jest.fn() };
  const player = new VideoAutoplayPlayer(video, coordinator);
  return { video, play, pause, coordinator, player };
}

describe('VideoAutoplayPlayer', () => {
  it('starts muted unless sound is on', () => {
    const { video, play, player } = setUp();

    player.start(false);
    expect(video.muted).toBe(true);

    player.start(true);
    expect(video.muted).toBe(false);
    expect(play).toHaveBeenCalledTimes(2);
  });

  it('falls back to muted when the browser refuses sound', async () => {
    const { video, play, player } = setUp();
    play.mockRejectedValueOnce(refusal());

    player.start(true);
    await settle();

    expect(video.muted).toBe(true);
    expect(play).toHaveBeenCalledTimes(2);
  });

  it('leaves the poster when even a muted start is refused', async () => {
    const { play, player } = setUp();
    play.mockRejectedValue(refusal());

    player.start(false);
    await settle();

    expect(play).toHaveBeenCalledTimes(1);
  });

  it('does not retry muted when a start with sound fails for another reason', async () => {
    const { video, play, player } = setUp();
    play.mockRejectedValueOnce(new DOMException('source swapped', 'AbortError'));

    player.start(true);
    await settle();

    expect(video.muted).toBe(false);
    expect(play).toHaveBeenCalledTimes(1);
  });

  it('keeps the user sound choice clear of its own queued changes', async () => {
    const { video, play, coordinator, player } = setUp();
    video.muted = true;
    await settle();
    coordinator.soundChanged.mockClear();
    play.mockRejectedValueOnce(refusal());

    player.start(true);
    await settle();

    expect(coordinator.soundChanged).not.toHaveBeenCalled();
    expect(video.muted).toBe(true);
    expect(play).toHaveBeenCalledTimes(2);
  });

  it('does not loop when a muted video is refused with and without sound', async () => {
    const { video, play, player } = setUp();
    video.muted = true;
    play.mockRejectedValue(refusal());

    player.start(true);
    await settle();

    expect(play).toHaveBeenCalledTimes(2);
  });

  it('pauses when stopped', () => {
    const { pause, player } = setUp();
    player.stop();
    expect(pause).toHaveBeenCalledTimes(1);
  });

  it('tells the coordinator whenever it plays', () => {
    const { video, coordinator, player } = setUp();
    video.dispatchEvent(new Event('play'));
    expect(coordinator.nowPlaying).toHaveBeenCalledWith(player);
  });

  it('reports the user unmuting and muting, but not its own muted start', async () => {
    const { video, coordinator, player } = setUp();

    player.start(false);
    await settle();
    expect(coordinator.soundChanged).not.toHaveBeenCalled();

    video.muted = false;
    await settle();
    expect(coordinator.soundChanged).toHaveBeenLastCalledWith(true);

    video.muted = true;
    await settle();
    expect(coordinator.soundChanged).toHaveBeenLastCalledWith(false);
  });
});
