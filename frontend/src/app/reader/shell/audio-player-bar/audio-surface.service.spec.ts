import { ApplicationRef } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { OverlayContainer } from '@angular/cdk/overlay';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { bigPlayerAudioSignals } from '../../../../testing/big-player-audio-signals';
import { AudioPlayerService } from '../../audio-player.service';
import { AudioSurface } from './audio-surface.service';

describe('AudioSurface', () => {
  let surface: AudioSurface;
  let container: HTMLElement;

  beforeEach(() => {
    const player = bigPlayerAudioSignals();
    const track = {
      url: 'https://x.test/ep.mp3',
      title: 'Episode 1',
      faviconUrl: null,
      imageUrl: null,
      durationInSeconds: 120,
    };
    player.current.set(track);
    player.tracks.set([track]);
    player.index.set(0);
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [provideRouter([]), { provide: AudioPlayerService, useValue: player }],
    });
    surface = TestBed.inject(AudioSurface);
    container = TestBed.inject(OverlayContainer).getContainerElement();
  });

  const settle = () => TestBed.inject(ApplicationRef).tick();
  const panes = () => container.querySelectorAll('.cdk-overlay-pane.app-big-player');
  const click = (selector: string) => {
    container.querySelector<HTMLButtonElement>(`app-big-player ${selector}`)!.click();
    settle();
  };

  it('toggles the playlist panel', () => {
    surface.togglePlaylist();
    expect(surface.playlistOpen()).toBe(true);

    surface.togglePlaylist();
    expect(surface.playlistOpen()).toBe(false);
  });

  it('opens one big player, labelled by the track title', () => {
    surface.openPlayer();
    surface.openPlayer();
    settle();

    expect(panes()).toHaveLength(1);
    const dialog = container.querySelector('[role="dialog"]')!;
    expect(dialog.getAttribute('aria-labelledby')).toBe('big-player-title');
  });

  it('closes the playlist panel when the big player opens', () => {
    surface.togglePlaylist();

    surface.openPlayer();
    settle();

    expect(surface.playlistOpen()).toBe(false);
  });

  it('swaps the big player for the playlist panel', () => {
    surface.openPlayer();
    settle();

    click('.show-playlist');

    expect(panes()).toHaveLength(0);
    expect(surface.playlistOpen()).toBe(true);
  });

  it('minimises to neither, and opens again afterwards', () => {
    surface.openPlayer();
    settle();

    click('.collapse');
    expect(panes()).toHaveLength(0);
    expect(surface.playlistOpen()).toBe(false);

    surface.openPlayer();
    settle();
    expect(panes()).toHaveLength(1);
  });
});
