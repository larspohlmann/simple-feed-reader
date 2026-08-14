import { Component } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { InfoTipComponent } from './info-tip.component';

@Component({
  imports: [InfoTipComponent],
  template: `<app-info-tip [text]="'The explanation.'" [label]="'Endpoint'" />`,
})
class HostComponent {}

@Component({
  imports: [InfoTipComponent],
  template: `
    <app-info-tip corner [text]="'A.'" [label]="'Static'" />
    <app-info-tip [corner]="true" [text]="'B.'" [label]="'Bound'" />
    <app-info-tip [text]="'C.'" [label]="'Plain'" />
  `,
})
class CornerHostComponent {}

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

  it('drops aria-controls while closed, because the panel it names does not exist', () => {
    const fixture = mount();

    expect(trigger(fixture).hasAttribute('aria-controls')).toBe(false);

    trigger(fixture).click();
    fixture.detectChanges();

    expect(trigger(fixture).getAttribute('aria-controls')).toBe(panel(fixture)!.id);
  });

  /**
   * The corner anchoring keys on a host class the component binds, so both
   * spellings of the input work. It used to key on the `corner` attribute,
   * which the bound form never writes — that anchored nothing, silently.
   */
  it('marks corner mode on the host for the static and the bound input alike', () => {
    const fixture = TestBed.createComponent(CornerHostComponent);
    fixture.detectChanges();

    const tips = Array.from(
      fixture.nativeElement.querySelectorAll('app-info-tip'),
    ) as HTMLElement[];

    expect(tips.map((tip) => tip.classList.contains('corner'))).toEqual([true, true, false]);
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
