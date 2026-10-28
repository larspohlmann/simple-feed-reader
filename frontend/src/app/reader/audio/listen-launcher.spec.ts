import { TestBed } from '@angular/core/testing';
import { Dialog } from '@angular/cdk/dialog';
import { of } from 'rxjs';
import { provideAccountIdentity } from '../../../testing/account-identity-testing';
import { StubAudioPlayer } from '../../../testing/stub-audio-player';
import { AudioPlayerService, AudioTrack } from '../audio-player.service';
import { ListenLauncher } from './listen-launcher';
import { ListenPositions } from './listen-positions';
import { ResumeChoice, ResumeDialogComponent } from './resume-dialog/resume-dialog.component';

const TRACK: AudioTrack = {
  url: 'https://x.test/long.mp3',
  title: 'The long episode',
  faviconUrl: null,
  imageUrl: null,
  durationInSeconds: 3600,
};

function setUp(choice: ResumeChoice | undefined = undefined) {
  localStorage.clear();
  const player = new StubAudioPlayer();
  const open = jest.fn(() => ({ closed: of(choice) }));
  TestBed.configureTestingModule({
    providers: [
      provideAccountIdentity(),
      { provide: AudioPlayerService, useValue: player.asService() },
      { provide: Dialog, useValue: { open } },
    ],
  });
  const started = jest.fn();
  return {
    player,
    open,
    started,
    launcher: TestBed.inject(ListenLauncher),
    leftAt: (seconds: number) =>
      TestBed.inject(ListenPositions).remember({ url: TRACK.url, seconds, durationSeconds: 3600 }),
  };
}

describe('ListenLauncher', () => {
  it('plays a track with no saved place at once', () => {
    const { player, open, started, launcher } = setUp();

    launcher.listen(TRACK, started);

    expect(open).not.toHaveBeenCalled();
    expect(player.played).toEqual([TRACK]);
    expect(started).toHaveBeenCalledTimes(1);
  });

  it('asks about a saved place, naming the episode, the place and the length', () => {
    const { open, launcher, leftAt } = setUp();
    leftAt(1421);

    launcher.listen(TRACK);

    expect(open).toHaveBeenCalledWith(ResumeDialogComponent, {
      data: { title: 'The long episode', seconds: 1421, durationSeconds: 3600 },
      panelClass: 'app-dialog',
    });
  });

  it('continues at the saved place', () => {
    const { player, started, launcher, leftAt } = setUp('continue');
    leftAt(1421);

    launcher.listen(TRACK, started);

    expect(player.playedFrom).toEqual([{ track: TRACK, seconds: 1421 }]);
    expect(player.played).toEqual([]);
    expect(started).toHaveBeenCalledTimes(1);
  });

  it('starts over from the beginning', () => {
    const { player, started, launcher, leftAt } = setUp('fromStart');
    leftAt(1421);

    launcher.listen(TRACK, started);

    expect(player.played).toEqual([TRACK]);
    expect(player.playedFrom).toEqual([]);
    expect(started).toHaveBeenCalledTimes(1);
  });

  it('plays nothing when the dialog is dismissed', () => {
    const { player, started, launcher, leftAt } = setUp(undefined);
    leftAt(1421);

    launcher.listen(TRACK, started);

    expect(player.played).toEqual([]);
    expect(player.playedFrom).toEqual([]);
    expect(started).not.toHaveBeenCalled();
  });

  it('never asks about the track already in the player', () => {
    const { player, open, launcher, leftAt } = setUp();
    leftAt(1421);
    player.current.set(TRACK);

    launcher.listen(TRACK);

    expect(open).not.toHaveBeenCalled();
    expect(player.played).toEqual([TRACK]);
  });
});
