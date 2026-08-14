import { Component } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { InfoTipComponent } from './info-tip.component';

@Component({
  imports: [InfoTipComponent],
  template: `<app-info-tip [text]="'The explanation.'" [label]="'Endpoint'" />`,
})
class HostComponent {}

describe('InfoTipComponent', () => {
  function mount(): ComponentFixture<HostComponent> {
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    return fixture;
  }

  const trigger = (fixture: ComponentFixture<HostComponent>): HTMLButtonElement =>
    fixture.nativeElement.querySelector('button.trigger') as HTMLButtonElement;

  const panel = (fixture: ComponentFixture<HostComponent>): HTMLElement | null =>
    fixture.nativeElement.querySelector('.panel');

  it('renders closed: a labelled trigger, no panel', () => {
    const fixture = mount();

    expect(trigger(fixture).getAttribute('aria-label')).toBe('Endpoint');
    expect(trigger(fixture).getAttribute('aria-expanded')).toBe('false');
    expect(panel(fixture)).toBeNull();
  });

  it('opens on click and wires the panel to the trigger', () => {
    const fixture = mount();

    trigger(fixture).click();
    fixture.detectChanges();

    expect(trigger(fixture).getAttribute('aria-expanded')).toBe('true');
    expect(panel(fixture)).not.toBeNull();
    expect(panel(fixture)!.textContent).toContain('The explanation.');
    expect(panel(fixture)!.getAttribute('role')).toBe('note');
    expect(trigger(fixture).getAttribute('aria-controls')).toBe(panel(fixture)!.id);
  });

  it('closes on a second click', () => {
    const fixture = mount();

    trigger(fixture).click();
    fixture.detectChanges();
    trigger(fixture).click();
    fixture.detectChanges();

    expect(panel(fixture)).toBeNull();
  });

  it('closes on Escape', () => {
    const fixture = mount();

    trigger(fixture).click();
    fixture.detectChanges();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    fixture.detectChanges();

    expect(panel(fixture)).toBeNull();
  });

  it('closes on a pointerdown outside, not on one inside', () => {
    const fixture = mount();

    trigger(fixture).click();
    fixture.detectChanges();
    panel(fixture)!.dispatchEvent(new Event('pointerdown', { bubbles: true }));
    fixture.detectChanges();
    expect(panel(fixture)).not.toBeNull();

    document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }));
    fixture.detectChanges();
    expect(panel(fixture)).toBeNull();
  });

  it('swallows the trigger click so a wrapping summary or label never activates', () => {
    const fixture = mount();
    const reached = jest.fn();
    document.body.addEventListener('click', reached);

    trigger(fixture).click();

    document.body.removeEventListener('click', reached);
    expect(reached).not.toHaveBeenCalled();
  });
});
