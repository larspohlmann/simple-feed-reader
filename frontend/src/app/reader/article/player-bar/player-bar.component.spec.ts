import { TestBed } from '@angular/core/testing';
import { VIDEO_MEDIA } from '../../../../testing/embed-media-testing';
import { StubAudioPlayer } from '../../../../testing/stub-audio-player';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { EmbedListen } from './embed-listen';
import { PlayerBarComponent } from './player-bar.component';

function render(inputs: { cinemaBox?: HTMLElement | null; listen?: EmbedListen | null }) {
  const player = new StubAudioPlayer();
  TestBed.configureTestingModule({
    imports: [PlayerBarComponent, provideTranslocoTesting()],
    providers: [{ provide: AudioPlayerService, useValue: player.asService() }],
  });
  const fixture = TestBed.createComponent(PlayerBarComponent);
  fixture.componentRef.setInput('cinemaBox', inputs.cinemaBox ?? null);
  fixture.componentRef.setInput('listen', inputs.listen ?? null);
  fixture.detectChanges();
  return { fixture, element: fixture.nativeElement as HTMLElement, player };
}

const TRACK: AudioTrack = {
  url: VIDEO_MEDIA.url,
  title: 'Talk',
  faviconUrl: null,
  imageUrl: null,
  durationInSeconds: null,
};

function offer(): EmbedListen {
  return { media: VIDEO_MEDIA, listen: jest.fn(), toggleQueued: jest.fn() };
}

const text = (element: Element | null) => element?.textContent?.replace(/\s+/g, '') ?? '';

describe('PlayerBarComponent', () => {
  it('is the player bar, offering Listen and the queue toggle before the cinema toggle', () => {
    const { element } = render({ cinemaBox: document.createElement('div'), listen: offer() });

    expect(element.classList).toContain('reader-player-bar');
    expect(Array.from(element.querySelectorAll('button'), (button) => button.className)).toEqual([
      'reader-listen__button',
      'reader-listen__button',
      'reader-cinema__toggle',
    ]);
  });

  it('labels Listen and names what it does', () => {
    const { element } = render({ listen: offer() });
    const listen = element.querySelectorAll<HTMLButtonElement>('.reader-listen__button')[0];

    expect(text(listen)).toBe('play_circleListen');
    expect(listen.title).toBe("Play the sound in the reader's audio player");
  });

  it('runs the offer when Listen is clicked', () => {
    const listen = offer();
    const { element } = render({ listen });

    element.querySelectorAll<HTMLButtonElement>('.reader-listen__button')[0].click();

    expect(listen.listen).toHaveBeenCalledTimes(1);
  });

  it('runs the offer when the queue toggle is clicked', () => {
    const listen = offer();
    const { element } = render({ listen });

    element.querySelectorAll<HTMLButtonElement>('.reader-listen__button')[1].click();

    expect(listen.toggleQueued).toHaveBeenCalledTimes(1);
  });

  it('shows the queue state through icon, label, pressed state and class, following the playlist', () => {
    const { fixture, element, player } = render({ listen: offer() });
    const toggle = () => element.querySelectorAll<HTMLButtonElement>('.reader-listen__button')[1];

    expect(text(toggle())).toBe('playlist_addAddtoplaylist');
    expect(toggle().getAttribute('aria-pressed')).toBe('false');
    expect(toggle().classList).not.toContain('reader-listen__button--queued');

    player.enqueue(TRACK);
    fixture.detectChanges();
    expect(text(toggle())).toBe('playlist_add_checkInplaylist');
    expect(toggle().getAttribute('aria-pressed')).toBe('true');
    expect(toggle().classList).toContain('reader-listen__button--queued');

    player.dequeue(VIDEO_MEDIA.url);
    fixture.detectChanges();
    expect(toggle().getAttribute('aria-pressed')).toBe('false');
  });

  it('offers no Listen controls without an offer', () => {
    const { element } = render({ cinemaBox: document.createElement('div') });

    expect(element.querySelector('.reader-listen__button')).toBeNull();
  });

  it('offers no cinema toggle without a cinema box', () => {
    const { element } = render({ listen: offer() });

    expect(element.querySelector('.reader-cinema__toggle')).toBeNull();
  });

  it('widens its box on click, flips the label and icon, and narrows again', () => {
    const box = document.createElement('div');
    box.scrollIntoView = jest.fn();
    const { fixture, element } = render({ cinemaBox: box });
    const toggle = element.querySelector<HTMLButtonElement>('.reader-cinema__toggle')!;

    expect(toggle.getAttribute('aria-keyshortcuts')).toBe('t');
    expect(text(toggle)).toBe('width_wideCinemamode(t)');

    toggle.click();
    fixture.detectChanges();
    expect(box.classList).toContain('reader-cinema--on');
    expect(text(toggle)).toBe('width_normalExitcinemamode(t)');
    expect(box.scrollIntoView).toHaveBeenCalledWith({ block: 'nearest' });

    toggle.click();
    fixture.detectChanges();
    expect(box.classList).not.toContain('reader-cinema--on');
    expect(text(toggle)).toBe('width_wideCinemamode(t)');
  });

  it('starts widened on a box already in cinema mode, and narrows it on click', () => {
    const box = document.createElement('div');
    box.classList.add('reader-cinema--on');
    box.scrollIntoView = jest.fn();
    const { fixture, element } = render({ cinemaBox: box });
    const toggle = element.querySelector<HTMLButtonElement>('.reader-cinema__toggle')!;

    expect(text(toggle)).toBe('width_normalExitcinemamode(t)');

    toggle.click();
    fixture.detectChanges();
    expect(box.classList).not.toContain('reader-cinema--on');
    expect(text(toggle)).toBe('width_wideCinemamode(t)');
  });
});
