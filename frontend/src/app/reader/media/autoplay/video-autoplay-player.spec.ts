import { VideoAutoplayPlayer } from './video-autoplay-player';

const refusal = () => new DOMException('autoplay with sound refused', 'NotAllowedError');
const settle = () => new Promise((resolve) => setTimeout(resolve));

function setUp() {
  const video = document.createElement('video');
  video.controls = true;
  const play = jest.spyOn(video, 'play').mockResolvedValue(undefined);
  const pause = jest.spyOn(video, 'pause').mockImplementation(() => undefined);
  const coordinator = { nowPlaying: jest.fn(), soundChanged: jest.fn(), unmutedByTap: jest.fn() };
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

  it('does not retry muted once stopped while the refused start was pending', async () => {
    const { play, player } = setUp();
    play.mockRejectedValueOnce(refusal());

    player.start(true);
    player.stop();
    await settle();

    expect(play).toHaveBeenCalledTimes(1);
  });

  it('reports nothing to the coordinator once disposed', async () => {
    const { video, coordinator, player } = setUp();

    player.dispose();
    video.dispatchEvent(new Event('play'));
    video.muted = !video.muted;
    await settle();

    expect(coordinator.nowPlaying).not.toHaveBeenCalled();
    expect(coordinator.soundChanged).not.toHaveBeenCalled();
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

  it('hides its controls while autoplaying and shows them again once stopped', () => {
    const { video, player } = setUp();

    player.start(false);
    expect(video.controls).toBe(false);
    expect(video.getAttribute('tabindex')).toBe('0');

    player.stop();
    expect(video.controls).toBe(true);
    expect(video.hasAttribute('tabindex')).toBe(false);
  });

  it('shows its controls and turns sound on when its autoplay is tapped', async () => {
    const { video, coordinator, player } = setUp();
    player.start(false);
    await settle();

    video.click();

    expect(video.controls).toBe(true);
    expect(video.muted).toBe(false);
    expect(coordinator.unmutedByTap).toHaveBeenCalledTimes(1);
    await settle();
    expect(coordinator.soundChanged).toHaveBeenLastCalledWith(true);
  });

  it('shows its controls once the autoplay ends', () => {
    const { video, player } = setUp();
    player.start(false);

    video.dispatchEvent(new Event('ended'));

    expect(video.controls).toBe(true);
    expect(video.hasAttribute('tabindex')).toBe(false);
  });

  it('reports the sound a tap turned on even when the coordinator unlocks in the same tap', async () => {
    const { video, coordinator, player } = setUp();
    player.start(false);
    await settle();

    video.click();
    player.unlockSound();
    await settle();

    expect(video.muted).toBe(false);
    expect(coordinator.soundChanged).toHaveBeenLastCalledWith(true);
  });

  it('leaves a tap on its visible controls to the browser', () => {
    const { video, coordinator } = setUp();
    video.muted = true;

    video.click();

    expect(video.muted).toBe(true);
    expect(coordinator.unmutedByTap).not.toHaveBeenCalled();
  });

  it('takes Enter or Space on its controlless autoplay as the tap', () => {
    const { video, coordinator, player } = setUp();
    player.start(false);
    const space = new KeyboardEvent('keydown', { key: ' ', cancelable: true });

    video.dispatchEvent(space);

    expect(space.defaultPrevented).toBe(true);
    expect(video.controls).toBe(true);
    expect(video.muted).toBe(false);
    expect(coordinator.unmutedByTap).toHaveBeenCalledTimes(1);
  });

  it('ignores other keys', () => {
    const { video, coordinator, player } = setUp();
    player.start(false);

    video.dispatchEvent(new KeyboardEvent('keydown', { key: 'a', cancelable: true }));

    expect(video.controls).toBe(false);
    expect(coordinator.unmutedByTap).not.toHaveBeenCalled();
  });

  it('unlocks sound without changing or reporting its muted state', async () => {
    const { video, coordinator, player } = setUp();
    player.start(false);
    await settle();

    player.unlockSound();
    await settle();

    expect(video.muted).toBe(true);
    expect(coordinator.soundChanged).not.toHaveBeenCalled();
  });
});
