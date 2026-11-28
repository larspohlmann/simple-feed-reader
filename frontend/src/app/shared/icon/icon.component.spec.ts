import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Component } from '@angular/core';
import { IconComponent } from './icon.component';
import { ICON_INK_SHIFTS } from './icon-ink-shifts';

@Component({ imports: [IconComponent], template: `<app-icon name="settings" />` })
class Host {}

@Component({
  imports: [IconComponent],
  template: `<app-icon name="settings" size="sm" />`,
})
class SizedHost {}

@Component({
  imports: [IconComponent],
  template: `<app-icon name="expand_more" size="dense" />`,
})
class DenseHost {}

@Component({
  imports: [IconComponent],
  template: `<app-icon name="circle" [fill]="filled" />`,
})
class FillHost {
  filled = false;
}

@Component({ imports: [IconComponent], template: `<app-icon name="home" />` })
class HomeHost {}

@Component({ imports: [IconComponent], template: `<app-icon name="home" [fill]="filled" />` })
class FilledHomeHost {
  filled = true;
}

@Component({ imports: [IconComponent], template: `<app-icon name="no_such_glyph" />` })
class UnknownHost {}

@Component({ imports: [IconComponent], template: `<app-icon name="add" />` })
class ZeroShiftHost {}

function spanOf<T>(host: new () => T): { fixture: ComponentFixture<T>; span: HTMLElement } {
  TestBed.configureTestingModule({ imports: [host] });
  const fixture = TestBed.createComponent(host);
  fixture.detectChanges();
  return { fixture, span: fixture.nativeElement.querySelector('span.material-symbols-outlined') };
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

  it('sizes the dense step from its own token', async () => {
    await TestBed.configureTestingModule({ imports: [DenseHost] }).compileComponents();
    const fixture = TestBed.createComponent(DenseHost);
    fixture.detectChanges();

    const host: HTMLElement = fixture.nativeElement.querySelector('app-icon');
    expect(host.style.fontSize).toBe('var(--icon-dense)');
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

  describe('ink centring', () => {
    it('lifts the glyph by its measured outlined shift', () => {
      expect(spanOf(HomeHost).span.style.transform).toBe(
        `translateY(${ICON_INK_SHIFTS['home'][0]}em)`,
      );
    });

    it('uses the filled shift when filled', () => {
      expect(spanOf(FilledHomeHost).span.style.transform).toBe(
        `translateY(${ICON_INK_SHIFTS['home'][1]}em)`,
      );
    });

    it('follows the fill input when it toggles', () => {
      const { fixture, span } = spanOf(FilledHomeHost);
      fixture.componentInstance.filled = false;
      fixture.detectChanges();
      expect(span.style.transform).toBe(`translateY(${ICON_INK_SHIFTS['home'][0]}em)`);
    });

    it('leaves an unmeasured glyph untransformed', () => {
      expect(spanOf(UnknownHost).span.style.transform).toBe('');
    });

    it('leaves a zero-shift glyph untransformed', () => {
      expect(spanOf(ZeroShiftHost).span.style.transform).toBe('');
    });

    it('keeps the inline font-size beside the transform', () => {
      const { span } = spanOf(HomeHost);
      expect(span.style.transform).not.toBe('');
      expect(span.style.fontSize).toBe('inherit');
    });
  });
});
