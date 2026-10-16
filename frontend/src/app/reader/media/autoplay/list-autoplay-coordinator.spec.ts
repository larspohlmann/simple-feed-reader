import { WritableSignal, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ListAutoplayService } from '../../../core/preferences/list-autoplay.service';
import { AutoplayPlayer, PlayingMedium } from './autoplay-player';
import { ListAutoplayCoordinator } from './list-autoplay-coordinator';
import { UnlockedVideoElements } from './unlocked-video-elements';
import { AUTOPLAY_REGION, AutoplayRegion } from './autoplay-region';
import {
  MockIntersectionObserver,
  installMockIntersectionObserver,
  restoreIntersectionObserver,
} from '../../../../testing/intersection-observer-testing';

const report = (element: Element, ratio: number) => MockIntersectionObserver.report(element, ratio);
beforeEach(installMockIntersectionObserver);

afterEach(() => {
  restoreIntersectionObserver();
  document.body.innerHTML = '';
});

type FakePlayer = AutoplayPlayer & { start: jest.Mock; stop: jest.Mock; unlockSound: jest.Mock };

const aDetachedPlayer = (): FakePlayer => ({
  element: document.createElement('div'),
  start: jest.fn(),
  stop: jest.fn(),
  unlockSound: jest.fn(),
});

function aPlayer(): FakePlayer {
  const player = aDetachedPlayer();
  document.body.append(player.element);
  return player;
}

type FakeMedium = PlayingMedium & { stop: jest.Mock };

const aMediumPlayedByHand = (): FakeMedium => ({
  element: document.createElement('audio'),
  stop: jest.fn(),
});

function setUp(enabled = true, region?: AutoplayRegion) {
  const setting: WritableSignal<boolean> = signal(enabled);
  const unlockedVideos = { refill: jest.fn(), take: jest.fn() };
  TestBed.configureTestingModule({
    providers: [
      ListAutoplayCoordinator,
      { provide: ListAutoplayService, useValue: { enabled: setting } },
      { provide: UnlockedVideoElements, useValue: unlockedVideos },
      region ? [{ provide: AUTOPLAY_REGION, useValue: region }] : [],
    ],
  });
  const coordinator = TestBed.inject(ListAutoplayCoordinator);
  TestBed.tick();
  return { coordinator, setting, unlockedVideos };
}

describe('ListAutoplayCoordinator', () => {
  it('starts the first player that is visible enough, muted', () => {
    const { coordinator } = setUp();
    const most = aPlayer();
    const half = aPlayer();
    coordinator.register(most);
    coordinator.register(half);

    report(most.element, 0.9);
    report(half.element, 0.7);

    expect(most.start).toHaveBeenCalledWith(false);
    expect(half.start).not.toHaveBeenCalled();
  });

  it('keeps an autoplayed player when a slightly more visible one appears', () => {
    const { coordinator } = setUp();
    const incumbent = aPlayer();
    const rival = aPlayer();
    coordinator.register(incumbent);
    coordinator.register(rival);

    report(incumbent.element, 0.7);
    report(rival.element, 0.9);

    expect(incumbent.stop).not.toHaveBeenCalled();
    expect(rival.start).not.toHaveBeenCalled();
  });

  it('keeps a hand-played player through visibility ticks', () => {
    const { coordinator } = setUp();
    const autoplayed = aPlayer();
    const byHand = aPlayer();
    coordinator.register(autoplayed);
    coordinator.register(byHand);
    report(autoplayed.element, 0.9);
    coordinator.nowPlaying(byHand);
    report(byHand.element, 0.5);

    report(autoplayed.element, 0.8);

    expect(byHand.stop).not.toHaveBeenCalled();
    expect(autoplayed.start).toHaveBeenCalledTimes(1);
  });

  it('lets a hand-played player give way once it is fully out of view', () => {
    const { coordinator } = setUp();
    const autoplayed = aPlayer();
    const byHand = aPlayer();
    coordinator.register(autoplayed);
    coordinator.register(byHand);
    report(autoplayed.element, 0.9);
    coordinator.nowPlaying(byHand);
    report(byHand.element, 0.5);
    report(autoplayed.element, 0.8);

    report(byHand.element, 0);

    expect(byHand.stop).toHaveBeenCalledTimes(1);
    expect(autoplayed.start).toHaveBeenCalledTimes(2);
  });

  it('does not stop an autoplayed player that reports playing', () => {
    const { coordinator } = setUp();
    const player = aPlayer();
    coordinator.register(player);
    report(player.element, 1);

    coordinator.nowPlaying(player);

    expect(player.stop).not.toHaveBeenCalled();
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
    report(leaving.element, 1);
    report(staying.element, 0.7);

    coordinator.unregister(leaving);

    expect(leaving.stop).toHaveBeenCalledTimes(1);
    expect(staying.start).toHaveBeenCalledTimes(1);
  });

  it('never chooses a player whose row has left the page', () => {
    const { coordinator } = setUp();
    const detached = aDetachedPlayer();
    const attached = aPlayer();
    coordinator.register(detached);
    coordinator.register(attached);

    report(detached.element, 1);
    report(attached.element, 0.7);

    expect(detached.start).not.toHaveBeenCalled();
    expect(attached.start).toHaveBeenCalledTimes(1);
  });

  it('unlocks every player and the spares on any tap', () => {
    const { coordinator, unlockedVideos } = setUp();
    const playing = aPlayer();
    const waiting = aPlayer();
    coordinator.register(playing);
    coordinator.register(waiting);
    report(playing.element, 1);

    coordinator.tapped();

    expect(playing.unlockSound).toHaveBeenCalledTimes(1);
    expect(waiting.unlockSound).toHaveBeenCalledTimes(1);
    expect(unlockedVideos.refill).toHaveBeenCalledTimes(1);
  });

  it('passes over a player that cannot unlock sound', () => {
    const { coordinator, unlockedVideos } = setUp();
    const short: AutoplayPlayer = {
      element: document.createElement('div'),
      start: jest.fn(),
      stop: jest.fn(),
    };
    document.body.append(short.element);
    coordinator.register(short);

    coordinator.tapped();

    expect(unlockedVideos.refill).toHaveBeenCalledTimes(1);
  });
  describe('media that only the user starts', () => {
    it('stops the autoplayed player and holds autoplay until the medium stops', () => {
      const { coordinator } = setUp();
      const player = aPlayer();
      const medium = aMediumPlayedByHand();
      coordinator.register(player);
      report(player.element, 1);

      coordinator.nowPlaying(medium);
      TestBed.tick();
      report(player.element, 1);
      expect(player.stop).toHaveBeenCalledTimes(1);
      expect(player.start).toHaveBeenCalledTimes(1);

      coordinator.stopped(medium);
      TestBed.tick();
      expect(player.start).toHaveBeenCalledTimes(2);
    });

    it('stops the medium playing before when another one is played', () => {
      const { coordinator } = setUp();
      const first = aMediumPlayedByHand();
      const second = aMediumPlayedByHand();

      coordinator.nowPlaying(first);
      coordinator.nowPlaying(second);

      expect(first.stop).toHaveBeenCalledTimes(1);
      expect(second.stop).not.toHaveBeenCalled();
    });

    it('holds autoplay until every medium played by hand has stopped', () => {
      const { coordinator } = setUp();
      const player = aPlayer();
      const post = aMediumPlayedByHand();
      const enclosure = aMediumPlayedByHand();
      coordinator.register(player);

      coordinator.nowPlaying(post);
      coordinator.nowPlaying(enclosure);
      coordinator.stopped(post);
      TestBed.tick();
      report(player.element, 1);
      expect(player.start).not.toHaveBeenCalled();

      coordinator.stopped(enclosure);
      TestBed.tick();
      expect(player.start).toHaveBeenCalledTimes(1);
    });

    it('is stopped by a player played by hand, which keeps playing', () => {
      const { coordinator } = setUp(false);
      const player = aPlayer();
      const medium = aMediumPlayedByHand();
      coordinator.register(player);

      coordinator.nowPlaying(medium);
      coordinator.nowPlaying(player);

      expect(medium.stop).toHaveBeenCalledTimes(1);
      expect(player.stop).not.toHaveBeenCalled();
    });

    it('no longer holds autoplay once a player played by hand stopped it', () => {
      const { coordinator } = setUp();
      const byHand = aPlayer();
      const next = aPlayer();
      const medium = aMediumPlayedByHand();
      coordinator.register(byHand);
      coordinator.register(next);

      coordinator.nowPlaying(medium);
      coordinator.nowPlaying(byHand);
      TestBed.tick();
      report(next.element, 1);
      report(byHand.element, 0);

      expect(next.start).toHaveBeenCalledTimes(1);
    });

    it('is left alone once it stopped on its own', () => {
      const { coordinator } = setUp(false);
      const player = aPlayer();
      const medium = aMediumPlayedByHand();
      coordinator.register(player);

      coordinator.nowPlaying(medium);
      coordinator.stopped(medium);
      coordinator.nowPlaying(player);

      expect(medium.stop).not.toHaveBeenCalled();
    });
  });

  describe('confined to a region', () => {
    it('autoplays only the players inside the region', () => {
      const region = document.createElement('section');
      document.body.append(region);
      const { coordinator } = setUp(true, () => region);
      const outside = aPlayer();
      const inside = aPlayer();
      region.append(inside.element);
      coordinator.register(outside);
      coordinator.register(inside);

      report(outside.element, 1);
      report(inside.element, 0.7);

      expect(outside.start).not.toHaveBeenCalled();
      expect(inside.start).toHaveBeenCalledTimes(1);
    });

    it('autoplays nothing while the region is absent', () => {
      const { coordinator } = setUp(true, () => undefined);
      const player = aPlayer();
      coordinator.register(player);

      report(player.element, 1);

      expect(player.start).not.toHaveBeenCalled();
    });

    it('fills the unlocked spares on a tap while the region is there', () => {
      const region = document.createElement('section');
      const { coordinator, unlockedVideos } = setUp(true, () => region);

      coordinator.tapped();

      expect(unlockedVideos.refill).toHaveBeenCalledTimes(1);
    });

    it('fills no spares while the region is absent, since nothing would take them', () => {
      const { coordinator, unlockedVideos } = setUp(true, () => undefined);

      coordinator.tapped();

      expect(unlockedVideos.refill).not.toHaveBeenCalled();
    });
  });
});
