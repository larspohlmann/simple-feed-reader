import type { ComponentFixture } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { ScrubberComponent } from '../app/reader/shell/audio-player-bar/scrubber/scrubber.component';

/** The range input inside a host's `<app-scrubber>`. */
export function scrubberInput(fixture: ComponentFixture<unknown>): HTMLInputElement {
  return fixture.debugElement
    .query(By.directive(ScrubberComponent))
    .query(By.css('input[type="range"]')).nativeElement;
}
