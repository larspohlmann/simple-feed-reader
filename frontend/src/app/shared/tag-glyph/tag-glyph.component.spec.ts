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
});
