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

@Component({
  imports: [IconComponent],
  template: `<app-icon name="circle" [fill]="filled" />`,
})
class FillHost {
  filled = false;
}

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
   * Regression guard: the vendor stylesheet's class rule on the glyph span
   * beats anything inherited from the host, silently pinning every icon at
   * 24px -- asserted inline since jsdom resolves neither inheritance nor em.
   */
  it('declares the glyph size inline, where the vendor class cannot outrank it', async () => {
    await TestBed.configureTestingModule({ imports: [SizedHost] }).compileComponents();
    const fixture = TestBed.createComponent(SizedHost);
    fixture.detectChanges();

    const span: HTMLElement = fixture.nativeElement.querySelector('span.material-symbols-outlined');
    expect(span.style.fontSize).toBe('inherit');
  });

  /* The `fill` input drives the FILL axis through a host class, so the SCSS can
     select it (`:host(.filled)`) without piercing encapsulation. Like the size,
     the axis itself is verified in the browser; this pins the class toggle. */
  it('toggles the `filled` host class from the fill input', async () => {
    await TestBed.configureTestingModule({ imports: [FillHost] }).compileComponents();
    const fixture = TestBed.createComponent(FillHost);
    fixture.detectChanges();

    const host: HTMLElement = fixture.nativeElement.querySelector('app-icon');
    expect(host.classList.contains('filled')).toBe(false);

    fixture.componentInstance.filled = true;
    fixture.detectChanges();
    expect(host.classList.contains('filled')).toBe(true);
  });
});
