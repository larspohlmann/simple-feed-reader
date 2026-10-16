import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { GifComponent } from './gif.component';
import { EntryMediumDto } from '../../entry-medium';
import {
  restoreIntersectionObserver,
  stubIntersectionObserver,
} from '../../../../testing/intersection-observer-testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';

const pickerGif: EntryMediumDto = {
  url: 'https://static.test/clap.gif?hh=280&ww=498',
  kind: 'image',
  width: 498,
  height: 280,
  previewImageUrl: 'https://cdn.test/clap.jpg',
  alt: 'Audience clapping',
  animated: true,
};
const gifv: EntryMediumDto = {
  url: 'https://files.test/wave.mp4',
  kind: 'animation',
  previewImageUrl: 'https://files.test/wave.jpg',
};

@Component({
  imports: [GifComponent],
  template: `<app-gif [medium]="medium" />`,
})
class HostComponent {
  medium: EntryMediumDto = pickerGif;
}

let play: jest.SpyInstance;
let pause: jest.SpyInstance;
let viewport: ReturnType<typeof stubIntersectionObserver>;

beforeEach(() => {
  viewport = stubIntersectionObserver();
  play = jest.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue(undefined);
  pause = jest.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => undefined);
});

afterEach(() => {
  jest.restoreAllMocks();
  restoreIntersectionObserver();
});

async function mount(medium: EntryMediumDto) {
  TestBed.configureTestingModule({ imports: [HostComponent, provideTranslocoTesting()] });
  const fixture = TestBed.createComponent(HostComponent);
  fixture.componentInstance.medium = medium;
  const parentClicks = jest.fn();
  fixture.nativeElement.addEventListener('click', parentClicks);
  fixture.detectChanges();
  const gif = fixture.nativeElement.querySelector('app-gif') as HTMLElement;
  const settle = async () => {
    fixture.detectChanges();
    await fixture.whenStable();
  };
  const scrolledTo = async (isNear: boolean) => {
    viewport.report(gif, isNear);
    await settle();
  };
  const tap = async () => {
    gif.click();
    await settle();
  };
  await scrolledTo(true);
  return { fixture, gif, parentClicks, tap, scrolledTo };
}

function imageOf(gif: HTMLElement): HTMLImageElement {
  return gif.querySelector('img')!;
}

describe('GifComponent', () => {
  it('animates near the viewport', async () => {
    const { gif } = await mount(pickerGif);

    expect(imageOf(gif).getAttribute('src')).toBe(pickerGif.url);
    expect(gif.getAttribute('aria-pressed')).toBe('true');
    expect(gif.querySelector('.badge')).toBeNull();
  });

  it('pauses on its still at a tap and plays again at the next', async () => {
    const { gif, parentClicks, tap } = await mount(pickerGif);

    await tap();
    expect(imageOf(gif).getAttribute('src')).toBe(pickerGif.previewImageUrl);
    expect(gif.getAttribute('aria-pressed')).toBe('false');
    expect(gif.querySelector('.badge')?.textContent).toContain('GIF');

    await tap();
    expect(imageOf(gif).getAttribute('src')).toBe(pickerGif.url);
    expect(parentClicks).not.toHaveBeenCalled();
  });

  it('is a button named by its alt text', async () => {
    const { gif } = await mount(pickerGif);

    expect(gif.getAttribute('role')).toBe('button');
    expect(gif.getAttribute('tabindex')).toBe('0');
    expect(gif.getAttribute('aria-label')).toBe('Audience clapping');
    expect(gif.hasAttribute('aria-hidden')).toBe(false);
  });

  it('is named GIF when it has no alt text', async () => {
    const { gif } = await mount(gifv);

    expect(gif.getAttribute('aria-label')).toBe('GIF');
  });

  it('is named GIF in German too when it has no alt text', async () => {
    const { gif, fixture } = await mount(gifv);

    TestBed.inject(TranslocoService).setActiveLang('de');
    fixture.detectChanges();

    expect(gif.getAttribute('aria-label')).toBe('GIF');
    expect(TestBed.inject(TranslocoService).translate('reader.media.gif')).toBe('GIF');
  });

  it('plays a looping clip muted and without controls', async () => {
    const { gif } = await mount(gifv);
    const clip = gif.querySelector('video')!;

    expect(clip.getAttribute('src')).toBe(gifv.url);
    expect(clip.getAttribute('poster')).toBe(gifv.previewImageUrl);
    expect(clip.getAttribute('preload')).toBe('none');
    expect([clip.muted, clip.hasAttribute('muted'), clip.loop, clip.controls]).toEqual([
      true,
      true,
      true,
      false,
    ]);
    expect(clip.hasAttribute('autoplay')).toBe(false);
    expect(clip.classList).toContain('animation');
    expect(play).toHaveBeenCalledTimes(1);
  });

  it('pauses its clip at a tap and plays it again at the next', async () => {
    const { tap } = await mount(gifv);
    pause.mockClear();

    await tap();
    expect(pause).toHaveBeenCalledTimes(1);

    await tap();
    expect(play).toHaveBeenCalledTimes(2);
  });

  it('downloads nothing before it is near the viewport', async () => {
    TestBed.configureTestingModule({ imports: [HostComponent, provideTranslocoTesting()] });
    const fixture = TestBed.createComponent(HostComponent);
    fixture.componentInstance.medium = gifv;
    fixture.detectChanges();
    await fixture.whenStable();

    expect(play).not.toHaveBeenCalled();
    expect(fixture.nativeElement.querySelector('video').getAttribute('preload')).toBe('none');
  });

  it('plays only while it is near the viewport', async () => {
    const { gif, scrolledTo } = await mount(pickerGif);
    expect(viewport.observed).toEqual([gif]);

    await scrolledTo(false);
    expect(imageOf(gif).getAttribute('src')).toBe(pickerGif.previewImageUrl);
    expect(gif.getAttribute('aria-pressed')).toBe('true');

    await scrolledTo(true);
    expect(imageOf(gif).getAttribute('src')).toBe(pickerGif.url);
  });

  it('pauses its clip once scrolled away', async () => {
    const { scrolledTo } = await mount(gifv);
    pause.mockClear();

    await scrolledTo(false);

    expect(pause).toHaveBeenCalledTimes(1);
  });

  it('stops watching the viewport once destroyed', async () => {
    const { fixture } = await mount(pickerGif);

    fixture.destroy();

    expect(viewport.disconnect).toHaveBeenCalled();
  });
});
