import { InjectionToken } from '@angular/core';

/** The element autoplay is confined to, while it is rendered. */
export type AutoplayRegion = () => Element | undefined;

/** Provided where autoplay covers only part of the page, as an article's replies; absent, it covers all. */
export const AUTOPLAY_REGION = new InjectionToken<AutoplayRegion>('AUTOPLAY_REGION');
