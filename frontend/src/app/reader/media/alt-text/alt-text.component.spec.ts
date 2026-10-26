import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AltTextComponent } from './alt-text.component';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';

@Component({
  imports: [AltTextComponent],
  template: `<app-alt-text text="A donkey in a field" />`,
})
class HostComponent {}

async function mount() {
  TestBed.configureTestingModule({ imports: [HostComponent, provideTranslocoTesting()] });
  const fixture = TestBed.createComponent(HostComponent);
  const parentClicks = jest.fn();
  fixture.nativeElement.addEventListener('click', parentClicks);
  fixture.detectChanges();
  await fixture.whenStable();
  const badge = fixture.nativeElement.querySelector('button') as HTMLButtonElement;
  const panel = () => fixture.nativeElement.querySelector('.text') as HTMLElement | null;
  return { fixture, parentClicks, badge, panel };
}

describe('AltTextComponent', () => {
  it('shows a collapsed ALT badge', async () => {
    const { badge, panel } = await mount();

    expect(badge.textContent?.trim()).toBe('ALT');
    expect(badge.getAttribute('aria-expanded')).toBe('false');
    expect(badge.getAttribute('aria-label')).toBe('Show image description');
    expect(panel()).toBeNull();
  });

  it('opens the text on a tap and closes it on a tap of the text', async () => {
    const { fixture, badge, panel } = await mount();

    badge.click();
    fixture.detectChanges();
    expect(panel()?.textContent?.trim()).toBe('A donkey in a field');
    expect(badge.getAttribute('aria-expanded')).toBe('true');
    expect(badge.getAttribute('aria-label')).toBe('Hide image description');

    panel()!.click();
    fixture.detectChanges();
    expect(panel()).toBeNull();
  });

  it('closes on Escape', async () => {
    const { fixture, badge, panel } = await mount();
    badge.click();
    fixture.detectChanges();

    badge.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    fixture.detectChanges();

    expect(panel()).toBeNull();
  });

  it('keeps its taps from reaching the parent', async () => {
    const { fixture, parentClicks, badge, panel } = await mount();

    badge.click();
    fixture.detectChanges();
    panel()!.click();

    expect(parentClicks).not.toHaveBeenCalled();
  });
});
