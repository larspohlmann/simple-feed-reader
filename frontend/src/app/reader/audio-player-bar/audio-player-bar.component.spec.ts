import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { AudioPlayerBarComponent } from './audio-player-bar.component';
import { AudioPlayerService, AudioTrack, SKIP_SECONDS } from '../audio-player.service';

function stub() {
  return {
    current: signal<AudioTrack | null>(null),
    playing: signal(false),
    position: signal(0),
    duration: signal(0),
    toggle: jest.fn(),
    seek: jest.fn(),
    skip: jest.fn(),
    stop: jest.fn(),
  };
}

let service: ReturnType<typeof stub>;

function render() {
  service = stub();
  TestBed.configureTestingModule({
    imports: [AudioPlayerBarComponent, provideTranslocoTesting()],
    providers: [{ provide: AudioPlayerService, useValue: service }],
  });
  const fixture = TestBed.createComponent(AudioPlayerBarComponent);
  fixture.detectChanges();
  return fixture;
}

const track: AudioTrack = {
  url: 'https://x.test/ep.mp3',
  title: 'Episode 1',
  faviconUrl: null,
  imageUrl: null,
  durationInSeconds: 120,
};

describe('AudioPlayerBarComponent', () => {
  it('renders nothing while no track is loaded', () => {
    const fixture = render();

    expect(fixture.nativeElement.textContent.trim()).toBe('');
  });

  it('shows the track title once a track is current', () => {
    const fixture = render();
    service.current.set(track);
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('Episode 1');
  });

  it('toggles playback from the play/pause control', () => {
    const fixture = render();
    service.current.set(track);
    fixture.detectChanges();

    fixture.debugElement.query(By.css('.play')).nativeElement.click();

    expect(service.toggle).toHaveBeenCalled();
  });

  it('drives the scrubber from position and duration', () => {
    const fixture = render();
    service.current.set(track);
    service.duration.set(120);
    service.position.set(30);
    fixture.detectChanges();

    const scrubber: HTMLInputElement = fixture.debugElement.query(
      By.css('.scrubber'),
    ).nativeElement;

    expect(scrubber.max).toBe('120');
    expect(scrubber.value).toBe('30');
  });

  it('seeks when the scrubber is moved', () => {
    const fixture = render();
    service.current.set(track);
    service.duration.set(120);
    fixture.detectChanges();

    const scrubber: HTMLInputElement = fixture.debugElement.query(
      By.css('.scrubber'),
    ).nativeElement;
    scrubber.value = '75';
    scrubber.dispatchEvent(new Event('input'));

    expect(service.seek).toHaveBeenCalledWith(75);
  });

  it('skips backward and forward by the shared step', () => {
    const fixture = render();
    service.current.set(track);
    fixture.detectChanges();

    fixture.debugElement.query(By.css('.back')).nativeElement.click();
    fixture.debugElement.query(By.css('.forward')).nativeElement.click();

    expect(service.skip).toHaveBeenNthCalledWith(1, -SKIP_SECONDS);
    expect(service.skip).toHaveBeenNthCalledWith(2, SKIP_SECONDS);
  });

  it('closes the player', () => {
    const fixture = render();
    service.current.set(track);
    fixture.detectChanges();

    fixture.debugElement.query(By.css('.close')).nativeElement.click();

    expect(service.stop).toHaveBeenCalled();
  });
});
