import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Component } from '@angular/core';
import { IconComponent, IconSize } from './icon.component';
import { ICON_INK_SHIFTS } from './icon-ink-shifts';

@Component({
  imports: [IconComponent],
  template: `<app-icon [name]="name" [size]="size" [fill]="filled" />`,
})
class Host {
  name = 'settings';
  size: IconSize = 'md';
  filled = false;
}

interface RenderedIcon {
  fixture: ComponentFixture<Host>;
  icon: HTMLElement;
  span: HTMLElement;
}

function renderIconSpan(fields: Partial<Host> = {}): RenderedIcon {
  TestBed.configureTestingModule({ imports: [Host] });
  const fixture = TestBed.createComponent(Host);
  Object.assign(fixture.componentInstance, fields);
  fixture.detectChanges();
  return {
    fixture,
    icon: fixture.nativeElement.querySelector('app-icon'),
    span: fixture.nativeElement.querySelector('span.material-symbols-outlined'),
  };
}

describe('IconComponent', () => {
  it('renders the ligature name inside a material-symbols span', () => {
    const { span } = renderIconSpan();
    expect(span.textContent?.trim()).toBe('settings');
    expect(span.getAttribute('aria-hidden')).toBe('true');
  });

  /**
   * Regression guard: the vendor stylesheet's class rule on the glyph span
   * beats anything inherited from the host, silently pinning every icon at
   * 24px -- asserted inline since jsdom resolves neither inheritance nor em.
   */
  it('declares the glyph size inline, where the vendor class cannot outrank it', () => {
    expect(renderIconSpan({ size: 'sm' }).span.style.fontSize).toBe('inherit');
  });

  it('sizes the dense step from its own token', () => {
    const { icon } = renderIconSpan({ name: 'expand_more', size: 'dense' });
    expect(icon.style.fontSize).toBe('var(--icon-dense)');
  });

  /* The `fill` input drives the FILL axis through a host class, so the SCSS can
     select it (`:host(.filled)`) without piercing encapsulation. Like the size,
     the axis itself is verified in the browser; this pins the class toggle. */
  it('toggles the `filled` host class from the fill input', () => {
    const { fixture, icon } = renderIconSpan({ name: 'circle' });
    expect(icon.classList.contains('filled')).toBe(false);

    fixture.componentInstance.filled = true;
    fixture.detectChanges();
    expect(icon.classList.contains('filled')).toBe(true);
  });

  describe('ink centring', () => {
    it('lifts the glyph by its measured outlined shift', () => {
      expect(renderIconSpan({ name: 'home' }).span.style.translate).toBe(
        `0 ${ICON_INK_SHIFTS['home'][0]}em`,
      );
    });

    it('uses the filled shift when filled', () => {
      expect(renderIconSpan({ name: 'home', filled: true }).span.style.translate).toBe(
        `0 ${ICON_INK_SHIFTS['home'][1]}em`,
      );
    });

    it('follows the fill input when it toggles', () => {
      const { fixture, span } = renderIconSpan({ name: 'home', filled: true });
      fixture.componentInstance.filled = false;
      fixture.detectChanges();
      expect(span.style.translate).toBe(`0 ${ICON_INK_SHIFTS['home'][0]}em`);
    });

    it('leaves an unmeasured glyph untransformed', () => {
      expect(renderIconSpan({ name: 'no_such_glyph' }).span.style.translate).toBe('');
    });

    it('leaves a zero-shift glyph untranslated', () => {
      const [name] = Object.entries(ICON_INK_SHIFTS).find(([, shifts]) => shifts[0] === 0) ?? [];
      expect(name).toBeDefined();
      expect(renderIconSpan({ name }).span.style.translate).toBe('');
    });

    it('leaves transform free for animations to own', () => {
      expect(renderIconSpan({ name: 'home' }).span.style.transform).toBe('');
    });

    it('keeps the inline font-size beside the translate', () => {
      const { span } = renderIconSpan({ name: 'home' });
      expect(span.style.translate).not.toBe('');
      expect(span.style.fontSize).toBe('inherit');
    });
  });
});
