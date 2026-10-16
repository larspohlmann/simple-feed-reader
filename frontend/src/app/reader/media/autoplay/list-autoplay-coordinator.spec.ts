import { WritableSignal, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ListAutoplayService } from '../../../core/preferences/list-autoplay.service';
import { AutoplayPlayer } from './autoplay-player';
import { ListAutoplayCoordinator } from './list-autoplay-coordinator';

let report: (element: Element, ratio: number) => void;
let realObserver: typeof IntersectionObserver;

beforeEach(() => {
  realObserver = globalThis.IntersectionObserver;
  globalThis.IntersectionObserver = class {
    constructor(callback: IntersectionObserverCallback) {
      report = (target, intersectionRatio) =>
        callback(
          [{ target, intersectionRatio } as unknown as IntersectionObserverEntry],
          {} as IntersectionObserver,
        );
    }
    readonly observe = jest.fn();
    readonly unobserve = jest.fn();
    readonly disconnect = jest.fn();
  } as unknown as typeof IntersectionObserver;
});

afterEach(() => (globalThis.IntersectionObserver = realObserver));

const aPlayer = (): AutoplayPlayer & { start: jest.Mock; stop: jest.Mock } => ({
  element: document.createElement('div'),
  start: jest.fn(),
  stop: jest.fn(),
});

function setUp(enabled = true) {
  const setting: WritableSignal<boolean> = signal(enabled);
  TestBed.configureTestingModule({
    providers: [
      ListAutoplayCoordinator,
      { provide: ListAutoplayService, useValue: { enabled: setting } },
    ],
  });
  const coordinator = TestBed.inject(ListAutoplayCoordinator);
  TestBed.tick();
  return { coordinator, setting };
}

describe('ListAutoplayCoordinator', () => {
  it('starts the most visible player, muted', () => {
    const { coordinator } = setUp();
    const half = aPlayer();
    const most = aPlayer();
    coordinator.register(half);
    coordinator.register(most);

    report(half.element, 0.7);
    report(most.element, 0.9);

    expect(most.start).toHaveBeenCalledWith(false);
    expect(half.stop).toHaveBeenCalledTimes(1);
  });

  it('starts nothing under the visibility threshold', () => {
    const { coordinator } = setUp();
    const player = aPlayer();
    coordinator.register(player);

    report(player.element, 0.5);

    expect(player.start).not.toHaveBeenCalled();
  });

  it('stops the playing player once it drops under the threshold, and restarts it when it returns', () => {
    const { coordinator } = setUp();
    const player = aPlayer();
    coordinator.register(player);

    report(player.element, 1);
    report(player.element, 0.2);
    report(player.element, 1);

    expect(player.stop).toHaveBeenCalledTimes(1);
    expect(player.start).toHaveBeenCalledTimes(2);
  });

  it('starts nothing while the setting is off, and stops its player when it is switched off', () => {
    const { coordinator, setting } = setUp(false);
    const player = aPlayer();
    coordinator.register(player);
    report(player.element, 1);
    expect(player.start).not.toHaveBeenCalled();

    setting.set(true);
    TestBed.tick();
    expect(player.start).toHaveBeenCalledTimes(1);

    setting.set(false);
    TestBed.tick();
    expect(player.stop).toHaveBeenCalledTimes(1);
  });

  it('stops its player while suspended and picks up again on resume', () => {
    const { coordinator } = setUp();
    const player = aPlayer();
    coordinator.register(player);
    report(player.element, 1);

    coordinator.suspend();
    TestBed.tick();
    expect(player.stop).toHaveBeenCalledTimes(1);

    coordinator.resume();
    TestBed.tick();
    expect(player.start).toHaveBeenCalledTimes(2);
  });

  it('stops the playing player when another one is played by hand, with the setting off too', () => {
    const { coordinator } = setUp(false);
    const first = aPlayer();
    const second = aPlayer();
    coordinator.register(first);
    coordinator.register(second);

    coordinator.nowPlaying(first);
    coordinator.nowPlaying(second);

    expect(first.stop).toHaveBeenCalledTimes(1);
    expect(second.stop).not.toHaveBeenCalled();
  });

  it('starts the following players with sound once sound is on, and muted once it is off again', () => {
    const { coordinator } = setUp();
    const first = aPlayer();
    const second = aPlayer();
    coordinator.register(first);
    coordinator.register(second);

    coordinator.soundChanged(true);
    report(first.element, 1);
    expect(first.start).toHaveBeenCalledWith(true);

    coordinator.soundChanged(false);
    report(first.element, 0);
    report(second.element, 1);
    expect(second.start).toHaveBeenCalledWith(false);
  });

  it('stops an unregistered playing player and moves on to the next visible one', () => {
    const { coordinator } = setUp();
    const leaving = aPlayer();
    const staying = aPlayer();
    coordinator.register(leaving);
    coordinator.register(staying);
    report(staying.element, 0.7);
    report(leaving.element, 1);

    coordinator.unregister(leaving);

    expect(leaving.stop).toHaveBeenCalledTimes(1);
    expect(staying.start).toHaveBeenCalledTimes(2);
  });
});
