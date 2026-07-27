import { TestBed } from '@angular/core/testing';
import { Component, signal } from '@angular/core';
import { TagGlyphComponent } from './tag-glyph.component';

@Component({
  imports: [TagGlyphComponent],
  template: `<app-tag-glyph [name]="name()" [color]="color()" [size]="'md'" />`,
})
class Host {
  readonly name = signal<string | null>(null);
  readonly color = signal<string | null>(null);
}

@Component({
  imports: [TagGlyphComponent],
  template: `<app-tag-glyph name="public" size="xs" />`,
})
class SmallHost {}

describe('TagGlyphComponent', () => {
  const mount = async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    return fixture;
  };

  it('renders the tinted glyph when a name is given', async () => {
    const fixture = await mount();
    fixture.componentInstance.name.set('public');
    fixture.componentInstance.color.set('#c08a3e');
    fixture.detectChanges();

    const el: HTMLElement = fixture.nativeElement;
    expect(el.querySelector('.material-symbols-outlined')?.textContent?.trim()).toBe('public');
    expect(el.querySelector('.dot')).toBeNull();
    expect((el.querySelector('app-icon') as HTMLElement).style.color).toBeTruthy();
  });

  it('falls back to the colour dot when no name is given', async () => {
    const fixture = await mount();
    fixture.componentInstance.color.set('#c08a3e');
    fixture.detectChanges();

    const el: HTMLElement = fixture.nativeElement;
    expect(el.querySelector('.material-symbols-outlined')).toBeNull();
    expect((el.querySelector('.dot') as HTMLElement).style.background).toBeTruthy();
  });

  it('falls back to the muted colour when no colour is given', async () => {
    const fixture = await mount();
    fixture.componentInstance.name.set('public');
    fixture.detectChanges();

    const icon = fixture.nativeElement.querySelector('app-icon') as HTMLElement;
    expect(icon.style.color).toBe('var(--text-muted)');
  });

  it('renders the dot even when both name and colour are absent', async () => {
    const fixture = await mount();
    expect((fixture.nativeElement as HTMLElement).querySelector('.dot')).not.toBeNull();
  });

  /* The layout property every consumer leans on: a dot is much smaller than a
     glyph, and lists mix tags with and without an icon, so the host must be the
     same square either way or the names beside it stop sharing a left edge. */
  it('occupies the same square whether it renders the glyph or the dot', async () => {
    const fixture = await mount();
    const host = (fixture.nativeElement as HTMLElement).querySelector(
      'app-tag-glyph',
    ) as HTMLElement;

    const withoutGlyph = { width: host.style.width, height: host.style.height };

    fixture.componentInstance.name.set('public');
    fixture.detectChanges();
    const withGlyph = { width: host.style.width, height: host.style.height };

    expect(withoutGlyph).toEqual({ width: 'var(--icon-md)', height: 'var(--icon-md)' });
    expect(withGlyph).toEqual(withoutGlyph);
  });

  it('sizes the square from the named size', async () => {
    await TestBed.configureTestingModule({ imports: [SmallHost] }).compileComponents();
    const fixture = TestBed.createComponent(SmallHost);
    fixture.detectChanges();
    const host = (fixture.nativeElement as HTMLElement).querySelector(
      'app-tag-glyph',
    ) as HTMLElement;

    expect(host.style.width).toBe('var(--icon-xs)');
    expect(host.style.height).toBe('var(--icon-xs)');
  });
});
