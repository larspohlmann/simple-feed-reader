import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { PostMediaComponent } from './post-media.component';
import { EntryMediumDto } from '../../entry-medium';
import { ListAutoplayCoordinator } from '../autoplay/list-autoplay-coordinator';
import { VideoAutoplayPlayer } from '../autoplay/video-autoplay-player';
import { fakeHls, firstPlay, resetFakeHls } from '../../../../testing/hls-testing';

jest.mock('hls.js', () => jest.requireActual('../../../../testing/hls-testing').fakeHlsModule);

let pause: jest.SpyInstance;

beforeEach(() => {
  resetFakeHls();
  pause = jest.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => undefined);
});

afterEach(() => pause.mockRestore());

const image = (index: number): EntryMediumDto => ({
  url: `https://cdn.test/${index}.jpg`,
  kind: 'image',
  width: 800,
  height: 600,
});
const clip: EntryMediumDto = {
  url: 'https://video.test/playlist.m3u8',
  kind: 'video',
  width: 1080,
  height: 1920,
  previewImageUrl: 'https://video.test/thumb.jpg',
};

@Component({
  imports: [PostMediaComponent],
  template: `<app-post-media [media]="media" [ratio]="1.5" videoLabel="Clip of a donkey" />`,
})
class HostComponent {
  media: EntryMediumDto[] = [];
}

async function mount(media: EntryMediumDto[]) {
  TestBed.configureTestingModule({ imports: [HostComponent] });
  const fixture = TestBed.createComponent(HostComponent);
  fixture.componentInstance.media = media;
  const parentClicks = jest.fn();
  fixture.nativeElement.addEventListener('click', parentClicks);
  fixture.detectChanges();
  await fixture.whenStable();
  return { fixture, parentClicks };
}

describe('PostMediaComponent', () => {
  it('lays out its pictures as a grid of their count', async () => {
    const { fixture } = await mount([image(1), image(2)]);
    const host = fixture.nativeElement.querySelector('app-post-media') as HTMLElement;

    expect(host.dataset['count']).toBe('2');
    expect(host.style.getPropertyValue('--ratio')).toBe('1.5');
    expect(host.querySelectorAll('img.item')).toHaveLength(2);
  });

  it('plays a video inline from its poster, named by the given label', async () => {
    const { fixture } = await mount([clip]);
    const video = fixture.nativeElement.querySelector('video.item') as HTMLVideoElement;

    expect(video.hasAttribute('controls')).toBe(true);
    expect(video.hasAttribute('playsinline')).toBe(true);
    expect(video.getAttribute('preload')).toBe('none');
    expect(video.getAttribute('poster')).toBe('https://video.test/thumb.jpg');
    expect(video.getAttribute('aria-label')).toBe('Clip of a donkey');
  });

  it('keeps a click on the video from reaching the parent', async () => {
    const { fixture, parentClicks } = await mount([clip, image(2)]);

    fixture.nativeElement.querySelector('video').click();
    expect(parentClicks).not.toHaveBeenCalled();

    fixture.nativeElement.querySelector('img').click();
    expect(parentClicks).toHaveBeenCalledTimes(1);
  });

  it('pauses its video and releases its stream when destroyed', async () => {
    const { fixture } = await mount([clip]);
    await firstPlay(fixture.nativeElement.querySelector('video'));

    fixture.destroy();

    expect(pause).toHaveBeenCalledTimes(1);
    expect(fakeHls.destroy).toHaveBeenCalledTimes(1);
  });
});

describe('PostMediaComponent in the list', () => {
  const coordinator = {
    register: jest.fn(),
    unregister: jest.fn(),
    nowPlaying: jest.fn(),
    soundChanged: jest.fn(),
  };

  beforeEach(() => Object.values(coordinator).forEach((spy) => spy.mockReset()));

  async function mountInList(media: EntryMediumDto[]) {
    TestBed.configureTestingModule({
      imports: [HostComponent],
      providers: [{ provide: ListAutoplayCoordinator, useValue: coordinator }],
    });
    const fixture = TestBed.createComponent(HostComponent);
    fixture.componentInstance.media = media;
    fixture.detectChanges();
    await fixture.whenStable();
    return fixture;
  }

  it('registers each video for autoplay', async () => {
    const fixture = await mountInList([clip, image(2)]);
    const video = fixture.nativeElement.querySelector('video');

    expect(coordinator.register).toHaveBeenCalledTimes(1);
    const player = coordinator.register.mock.calls[0][0] as VideoAutoplayPlayer;
    expect(player).toBeInstanceOf(VideoAutoplayPlayer);
    expect(player.element).toBe(video);
  });

  it('unregisters its videos when destroyed', async () => {
    const fixture = await mountInList([clip]);
    const player = coordinator.register.mock.calls[0][0];

    fixture.destroy();

    expect(coordinator.unregister).toHaveBeenCalledWith(player);
  });

  it('unregisters a video a re-render removed', async () => {
    const fixture = await mountInList([clip]);
    const player = coordinator.register.mock.calls[0][0];

    fixture.componentInstance.media = [image(1)];
    fixture.detectChanges();
    await fixture.whenStable();

    expect(coordinator.unregister).toHaveBeenCalledWith(player);
  });
});
