import {
  DestroyRef,
  ElementRef,
  Injectable,
  computed,
  effect,
  inject,
  signal,
  untracked,
} from '@angular/core';
import { ListAutoplayService } from '../../../core/preferences/list-autoplay.service';
import { AutoplayPlayer, PlayingMedium } from './autoplay-player';
import { UnlockedVideoElements } from './unlocked-video-elements';

const VISIBLE_ENOUGH = 0.6;
const THRESHOLDS = [0, 0.2, 0.4, 0.6, 0.8, 1];

/** Plays the most visible video of the list or the article that provides it, one at a time; a
 *  medium the user plays stops the rest, and one that never autoplays holds autoplay until it stops. */
@Injectable()
export class ListAutoplayCoordinator {
  private readonly setting = inject(ListAutoplayService);
  private readonly unlockedVideos = inject(UnlockedVideoElements);
  private readonly suspended = signal(false);
  private readonly heldByHand = signal(false);
  private readonly active = computed(
    () => this.setting.enabled() && !this.suspended() && !this.heldByHand(),
  );
  private readonly players = new Map<Element, AutoplayPlayer>();
  private readonly playingByHand = new Set<PlayingMedium>();
  private readonly visibility = new Map<Element, number>();
  private readonly observer =
    typeof IntersectionObserver === 'undefined'
      ? null
      : new IntersectionObserver((changes) => this.measure(changes), { threshold: THRESHOLDS });
  private current: { player: AutoplayPlayer; byHand: boolean } | null = null;
  private soundOn = false;
  private mayAutoplay: (element: Element) => boolean = () => true;

  constructor() {
    effect(() => {
      const active = this.active();
      untracked(() => (active ? this.choose() : this.stopCurrent()));
    });
    inject(DestroyRef).onDestroy(() => this.observer?.disconnect());
    // Capture phase: rows and the video slot stop propagation.
    inject(ElementRef<HTMLElement>, { optional: true })?.nativeElement.addEventListener(
      'click',
      () => this.tapped(),
      { capture: true },
    );
  }

  register(player: AutoplayPlayer): void {
    this.players.set(player.element, player);
    this.observer?.observe(player.element);
  }

  unregister(player: AutoplayPlayer): void {
    this.players.delete(player.element);
    this.visibility.delete(player.element);
    this.observer?.unobserve(player.element);
    if (this.current?.player !== player) return;
    this.stopCurrent();
    this.choose();
  }

  nowPlaying(medium: PlayingMedium): void {
    if (this.current?.player === medium) return;
    this.stopCurrent();
    this.stopPlayingByHandBut(medium);
    const player = this.players.get(medium.element);
    if (player === medium) this.current = { player, byHand: true };
    else this.playingByHand.add(medium);
    this.heldByHand.set(this.playingByHand.size > 0);
  }

  stopped(medium: PlayingMedium): void {
    if (this.playingByHand.delete(medium)) this.heldByHand.set(this.playingByHand.size > 0);
  }

  autoplayOnlyWithin(region: () => Element | undefined): void {
    this.mayAutoplay = (element) => region()?.contains(element) ?? false;
  }

  soundChanged(soundOn: boolean): void {
    this.soundOn = soundOn;
  }

  /** Must run synchronously inside a tap's event handler. */
  tapped(): void {
    for (const player of this.players.values()) player.unlockSound?.();
    this.unlockedVideos.refill();
  }

  suspend(): void {
    this.suspended.set(true);
  }

  resume(): void {
    this.suspended.set(false);
  }

  private measure(changes: IntersectionObserverEntry[]): void {
    for (const change of changes) this.visibility.set(change.target, change.intersectionRatio);
    this.choose();
  }

  private choose(): void {
    if (!this.active()) return;
    if (this.current && this.holdsOn(this.current)) return;
    const candidate = this.mostVisible();
    if (candidate === (this.current?.player ?? null)) return;
    this.stopCurrent();
    if (candidate === null) return;
    this.current = { player: candidate, byHand: false };
    candidate.start(this.soundOn);
  }

  private holdsOn({ player, byHand }: { player: AutoplayPlayer; byHand: boolean }): boolean {
    const ratio = this.visibility.get(player.element) ?? 0;
    return byHand ? ratio > 0 : ratio >= VISIBLE_ENOUGH;
  }

  private mostVisible(): AutoplayPlayer | null {
    let best: AutoplayPlayer | null = null;
    let bestRatio = 0;
    for (const [element, ratio] of this.visibility) {
      const player = this.players.get(element);
      if (!player || !this.mayStart(element) || ratio < VISIBLE_ENOUGH || ratio <= bestRatio) {
        continue;
      }
      best = player;
      bestRatio = ratio;
    }
    return best;
  }

  private stopPlayingByHandBut(medium: PlayingMedium): void {
    for (const other of this.playingByHand) {
      if (other === medium) continue;
      this.playingByHand.delete(other);
      other.stop();
    }
  }

  private mayStart(element: Element): boolean {
    return element.isConnected && this.mayAutoplay(element);
  }

  private stopCurrent(): void {
    this.current?.player.stop();
    this.current = null;
  }
}
