import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { AudioPlayerService } from '../../audio-player.service';
import type { EmbedMedia } from '../../audio/embed-media';
import { EmbedListen } from './embed-listen';
import { PlayerBarComponent } from './player-bar.component';

const MEDIA: EmbedMedia = {
  kind: 'youtube',
  url: 'https://www.youtube.com/watch?v=GhUuOxrCato',
  posterUrl: null,
};

class QueueStub {
  readonly queued = signal<string[]>([]);
  isQueued(url: string): boolean {
    return this.queued().includes(url);
  }
}

function render(inputs: { cinemaBox?: HTMLElement | null; listen?: EmbedListen | null }) {
  const queue = new QueueStub();
  TestBed.configureTestingModule({
    imports: [PlayerBarComponent, provideTranslocoTesting()],
    providers: [{ provide: AudioPlayerService, useValue: queue }],
  });
  const fixture = TestBed.createComponent(PlayerBarComponent);
  fixture.componentRef.setInput('cinemaBox', inputs.cinemaBox ?? null);
  fixture.componentRef.setInput('listen', inputs.listen ?? null);
  fixture.detectChanges();
  return { fixture, element: fixture.nativeElement as HTMLElement, queue };
}

function offer(): EmbedListen {
  return {
    media: MEDIA,
    frame: document.createElement('iframe'),
    actions: { listen: jest.fn(), toggleQueued: jest.fn() },
  };
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

  it('hands Listen the media and the frame that shows it', () => {
    const listen = offer();
    const { element } = render({ listen });

    element.querySelectorAll<HTMLButtonElement>('.reader-listen__button')[0].click();

    expect(listen.actions.listen).toHaveBeenCalledWith(MEDIA, listen.frame);
  });

  it('hands the queue toggle the media', () => {
    const listen = offer();
    const { element } = render({ listen });

    element.querySelectorAll<HTMLButtonElement>('.reader-listen__button')[1].click();

    expect(listen.actions.toggleQueued).toHaveBeenCalledWith(MEDIA);
  });

  it('shows the queue state through icon, label, pressed state and class, following the playlist', () => {
    const { fixture, element, queue } = render({ listen: offer() });
    const toggle = () => element.querySelectorAll<HTMLButtonElement>('.reader-listen__button')[1];

    expect(text(toggle())).toBe('playlist_addAddtoplaylist');
    expect(toggle().getAttribute('aria-pressed')).toBe('false');
    expect(toggle().classList).not.toContain('reader-listen__button--queued');

    queue.queued.set([MEDIA.url]);
    fixture.detectChanges();
    expect(text(toggle())).toBe('playlist_add_checkInplaylist');
    expect(toggle().getAttribute('aria-pressed')).toBe('true');
    expect(toggle().classList).toContain('reader-listen__button--queued');

    queue.queued.set([]);
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
