import { Injectable, InjectionToken, inject } from '@angular/core';

/** The element autoplay is confined to, while it is rendered. */
export type AutoplayRegion = () => Element | undefined;

/** Provided where autoplay covers only part of the page, as an article's replies; absent, it covers all. */
export const AUTOPLAY_REGION = new InjectionToken<AutoplayRegion>('AUTOPLAY_REGION');

/** A region the view hands over from its own query; nothing autoplays until it does. */
@Injectable()
export class HandedAutoplayRegion {
  private handed: AutoplayRegion = () => undefined;
  readonly region: AutoplayRegion = () => this.handed();

  handOver(region: AutoplayRegion): void {
    this.handed = region;
  }
}

export function handedAutoplayRegion(): AutoplayRegion {
  return inject(HandedAutoplayRegion).region;
}
