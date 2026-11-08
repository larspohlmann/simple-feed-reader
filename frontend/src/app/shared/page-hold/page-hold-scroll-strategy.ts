import { Provider, inject } from '@angular/core';
import { DIALOG_SCROLL_STRATEGY } from '@angular/cdk/dialog';
import type { ScrollStrategy } from '@angular/cdk/overlay';
import { PageHold } from './page-hold';

/** Holds the page while its overlay is attached, instead of pinning `<html>` (#2100). */
export class PageHoldScrollStrategy implements ScrollStrategy {
  private release: (() => void) | null = null;

  constructor(private readonly pageHold: PageHold) {}

  attach = (): void => undefined;

  enable(): void {
    this.release ??= this.pageHold.hold();
  }

  disable(): void {
    this.release?.();
    this.release = null;
  }
}

export function provideHeldPageDialogs(): Provider {
  return {
    provide: DIALOG_SCROLL_STRATEGY,
    useFactory: () => {
      const pageHold = inject(PageHold);
      return () => new PageHoldScrollStrategy(pageHold);
    },
  };
}
