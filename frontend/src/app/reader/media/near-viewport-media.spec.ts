import { TestBed } from '@angular/core/testing';
import { NearViewportMedia, NearViewportClient, keepNear } from './near-viewport-media';
import {
  MockIntersectionObserver,
  installMockIntersectionObserver,
  restoreIntersectionObserver,
} from '../../../testing/intersection-observer-testing';

const client = (): jest.Mocked<NearViewportClient> => ({ approach: jest.fn(), leave: jest.fn() });

function service(): NearViewportMedia {
  TestBed.configureTestingModule({ providers: [NearViewportMedia] });
  return TestBed.inject(NearViewportMedia);
}

beforeEach(installMockIntersectionObserver);
afterEach(restoreIntersectionObserver);

describe('NearViewportMedia', () => {
  it('measures against the list scroller, a screen beyond its edges', () => {
    const scroller = document.createElement('div');
    service().scrollWithin(scroller);

    expect(MockIntersectionObserver.instances[0].options).toEqual({
      root: scroller,
      rootMargin: '100% 0px',
    });
  });

  it('tells a watched element when it comes near and when it goes far', () => {
    const media = service();
    const video = document.createElement('video');
    const watcher = client();
    media.scrollWithin(document.createElement('div'));
    media.watch(video, watcher);

    MockIntersectionObserver.report(video, 0.1);
    expect(watcher.approach).toHaveBeenCalledTimes(1);

    MockIntersectionObserver.report(video, 0);
    expect(watcher.leave).toHaveBeenCalledTimes(1);
  });

  it('starts watching what was registered before the scroller was known', () => {
    const media = service();
    const video = document.createElement('video');
    media.watch(video, client());

    media.scrollWithin(document.createElement('div'));

    expect(MockIntersectionObserver.observes(video)).toBe(true);
  });

  it('moves every watched element to a new scroller', () => {
    const media = service();
    const video = document.createElement('video');
    media.scrollWithin(document.createElement('div'));
    media.watch(video, client());

    media.scrollWithin(document.createElement('div'));

    expect(MockIntersectionObserver.instances[0].targets.size).toBe(0);
    expect(MockIntersectionObserver.instances[1].targets.has(video)).toBe(true);
  });

  it('forgets an element it no longer watches', () => {
    const media = service();
    const video = document.createElement('video');
    const watcher = client();
    media.scrollWithin(document.createElement('div'));
    media.watch(video, watcher);

    media.unwatch(video);
    MockIntersectionObserver.report(video, 1);

    expect(watcher.approach).not.toHaveBeenCalled();
  });

  it('brings everything near at once where the browser cannot observe', () => {
    restoreIntersectionObserver();
    const original = globalThis.IntersectionObserver;
    // @ts-expect-error -- simulating a browser without IntersectionObserver
    delete globalThis.IntersectionObserver;
    const watcher = client();

    service().watch(document.createElement('video'), watcher);

    expect(watcher.approach).toHaveBeenCalledTimes(1);
    globalThis.IntersectionObserver = original;
  });
});

describe('keepNear', () => {
  it('brings the element near at once without a service', () => {
    const watcher = client();

    keepNear(null, document.createElement('video'), watcher);

    expect(watcher.approach).toHaveBeenCalledTimes(1);
  });

  it('hands the element to the service otherwise', () => {
    const media = service();
    const watch = jest.spyOn(media, 'watch');
    const video = document.createElement('video');
    const watcher = client();

    keepNear(media, video, watcher);

    expect(watch).toHaveBeenCalledWith(video, watcher);
    expect(watcher.approach).not.toHaveBeenCalled();
  });
});
