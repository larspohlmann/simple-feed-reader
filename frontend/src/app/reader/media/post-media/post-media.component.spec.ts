import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { PostMediaComponent } from './post-media.component';
import { EntryMediumDto } from '../../entry-medium';
import { ListAutoplayCoordinator } from '../autoplay/list-autoplay-coordinator';
import { VideoAutoplayPlayer } from '../autoplay/video-autoplay-player';
import { UnlockedVideoElements } from '../autoplay/unlocked-video-elements';
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

const gif: EntryMediumDto = {
  url: 'https://files.test/clap.mp4',
  kind: 'animation',
  width: 498,
  height: 280,
  previewImageUrl: 'https://files.test/clap.jpg',
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
    const video = fixture.nativeElement.querySelector('.video-slot video') as HTMLVideoElement;

    expect(video.hasAttribute('controls')).toBe(true);
    expect(video.hasAttribute('playsinline')).toBe(true);
    expect(video.getAttribute('preload')).toBe('none');
    expect(video.getAttribute('poster')).toBe('https://video.test/thumb.jpg');
    expect(video.getAttribute('aria-label')).toBe('Clip of a donkey');
    expect(video.getAttribute('src')).toBe(clip.url);
  });

  it('keeps a click on the video from reaching the parent', async () => {
    const { fixture, parentClicks } = await mount([clip, image(2)]);

    fixture.nativeElement.querySelector('video').click();
    expect(parentClicks).not.toHaveBeenCalled();

    fixture.nativeElement.querySelector('img').click();
    expect(parentClicks).toHaveBeenCalledTimes(1);
  });

  it('shows a looping clip and an animated picture as GIFs, and a picture with a still as a picture', async () => {
    const pickerGif: EntryMediumDto = {
      ...image(1),
      previewImageUrl: 'https://cdn.test/1-still.jpg',
      animated: true,
    };
    const stillPicture: EntryMediumDto = {
      ...image(2),
      previewImageUrl: 'https://cdn.test/2-still.jpg',
    };
    const { fixture } = await mount([gif, pickerGif, stillPicture]);
    const items = Array.from(
      fixture.nativeElement.querySelectorAll('app-post-media > .item') as NodeListOf<HTMLElement>,
    );

    expect(items.map((item) => item.tagName.toLowerCase())).toEqual(['app-gif', 'app-gif', 'img']);
    expect(fixture.nativeElement.querySelector('.video-slot')).toBeNull();
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
    tapped: jest.fn(),
  };

  beforeEach(() => Object.values(coordinator).forEach((spy) => spy.mockReset()));

  async function mountInList(
    media: EntryMediumDto[],
    unlockedVideos = { take: () => document.createElement('video') },
  ) {
    TestBed.configureTestingModule({
      imports: [HostComponent],
      providers: [
        { provide: ListAutoplayCoordinator, useValue: coordinator },
        { provide: UnlockedVideoElements, useValue: unlockedVideos },
      ],
    });
    const fixture = TestBed.createComponent(HostComponent);
    fixture.componentInstance.media = media;
    fixture.detectChanges();
    await fixture.whenStable();
    return fixture;
  }

  it("mounts each video from the list's unlocked elements", async () => {
    const unlocked = document.createElement('video');
    const fixture = await mountInList([clip], { take: () => unlocked });

    expect(fixture.nativeElement.querySelector('.video-slot video')).toBe(unlocked);
    expect(unlocked.controls).toBe(true);
    expect(unlocked.getAttribute('src')).toBe(clip.url);
    expect(unlocked.getAttribute('poster')).toBe(clip.previewImageUrl);
  });

  it('keeps its mounted video across a re-render', async () => {
    const take = jest.fn(() => document.createElement('video'));
    const fixture = await mountInList([clip], { take });

    fixture.componentInstance.media = [clip, image(2)];
    fixture.detectChanges();
    await fixture.whenStable();

    expect(take).toHaveBeenCalledTimes(1);
    expect(fixture.nativeElement.querySelectorAll('video')).toHaveLength(1);
  });

  it('registers each video for autoplay', async () => {
    const fixture = await mountInList([clip, image(2)]);
    const video = fixture.nativeElement.querySelector('video');

    expect(coordinator.register).toHaveBeenCalledTimes(1);
    const player = coordinator.register.mock.calls[0][0] as VideoAutoplayPlayer;
    expect(player).toBeInstanceOf(VideoAutoplayPlayer);
    expect(player.element).toBe(video);
  });

  it('leaves a GIF out of the one-at-a-time autoplay', async () => {
    const take = jest.fn(() => document.createElement('video'));
    const fixture = await mountInList([gif, clip], { take });

    expect(take).toHaveBeenCalledTimes(1);
    expect(coordinator.register).toHaveBeenCalledTimes(1);
    const player = coordinator.register.mock.calls[0][0] as VideoAutoplayPlayer;
    expect(player.element).toBe(fixture.nativeElement.querySelector('.video-slot video'));
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
