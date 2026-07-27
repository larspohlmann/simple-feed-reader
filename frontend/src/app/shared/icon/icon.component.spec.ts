import { TestBed } from '@angular/core/testing';
import { Component } from '@angular/core';
import { IconComponent } from './icon.component';

@Component({ imports: [IconComponent], template: `<app-icon name="settings" />` })
class Host {}

@Component({
  imports: [IconComponent],
  template: `<app-icon name="settings" size="sm" />`,
})
class SizedHost {}

describe('IconComponent', () => {
  it('renders the ligature name inside a material-symbols span', async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    const span: HTMLElement = fixture.nativeElement.querySelector('span.material-symbols-outlined');
    expect(span.textContent?.trim()).toBe('settings');
    expect(span.getAttribute('aria-hidden')).toBe('true');
  });

  /**
   * Regression guard. The size is applied to the host, but the vendor stylesheet
   * (`node_modules/material-symbols/outlined.css`) sets `font-size: 24px` on the
   * glyph span itself, and a class rule on the element beats anything inherited
   * from its parent. That pinned every icon in the app to 24px, and no test
   * noticed, because the host still reported the size it had been given.
   *
   * This asserts the inline declaration rather than a computed size because
   * jsdom resolves neither inheritance nor `em`, and the vendor stylesheet is
   * loaded by angular.json and so is absent from the TestBed entirely — a
   * computed-value assertion here would pass with the bug present. The real
   * sizes are verified in the browser; this only pins the mechanism so the
   * declaration cannot be quietly dropped again.
   */
  it('declares the glyph size inline, where the vendor class cannot outrank it', async () => {
    await TestBed.configureTestingModule({ imports: [SizedHost] }).compileComponents();
    const fixture = TestBed.createComponent(SizedHost);
    fixture.detectChanges();

    const span: HTMLElement = fixture.nativeElement.querySelector('span.material-symbols-outlined');
    expect(span.style.fontSize).toBe('inherit');
  });
});
