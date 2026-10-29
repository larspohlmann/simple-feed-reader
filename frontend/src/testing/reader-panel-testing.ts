import { TestBed } from '@angular/core/testing';

export function openReaderPanels(host: HTMLElement): void {
  for (const toggle of Array.from(
    host.querySelectorAll<HTMLButtonElement>('.reader-panel__toggle'),
  )) {
    if (toggle.getAttribute('aria-expanded') === 'false') toggle.click();
  }
  TestBed.tick();
}
