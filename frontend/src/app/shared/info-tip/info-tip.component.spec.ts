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
  template: `<label class="row"
    ><input type="checkbox" /><span>Ask</span
    ><app-info-tip [text]="'The explanation.'" [label]="'Ask'"
  /></label>`,
})
class LabelRowHostComponent {}

@Component({
  imports: [InfoTipComponent],
  template: `<app-info-tip [text]="'First.'" [label]="'First'" />
    <app-info-tip [text]="'Second.'" [label]="'Second'" />`,
})
class TwoTipsHostComponent {}

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
   * The panel floats as a popover (#541): it is `position: fixed`, placed from
   * the trigger's rect on open, so it never shifts sibling layout. It stays a
   * child of `.wrap`, beside the trigger. jsdom resolves no stylesheet, so this
   * pins only that structure; the out-of-flow geometry and viewport clamping
   * are verified in a real browser.
   */
  it('keeps the panel a sibling of the trigger, inside the positioning wrapper', () => {
    const fixture = mount();

    trigger(fixture).click();
    fixture.detectChanges();

    const wrap = trigger(fixture).parentElement!;
    expect(panel(fixture)!.parentElement).toBe(wrap);
    expect(wrap.parentElement).toBe(fixture.nativeElement.querySelector('app-info-tip'));
  });

  /**
   * The panel now sits inside the row it explains, and `app-field` puts one
   * inside a wrapping `<label>`. A click that reached the label would activate
   * the control the tip is explaining, so the panel swallows it — the same
   * guard the trigger has always had, for the same reason.
   */
  it('swallows a click on the panel, so a wrapping label cannot toggle its control', () => {
    const fixture = TestBed.createComponent(LabelRowHostComponent);
    fixture.detectChanges();
    const row = fixture.nativeElement.querySelector('label.row') as HTMLLabelElement;
    const reached = jest.fn();
    row.addEventListener('click', reached);

    (fixture.nativeElement.querySelector('button.trigger') as HTMLButtonElement).click();
    fixture.detectChanges();
    (fixture.nativeElement.querySelector('.panel') as HTMLElement).click();

    expect(reached).not.toHaveBeenCalled();
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

  it('closes an already-open tip when another one opens: one panel at a time', () => {
    const fixture = TestBed.createComponent(TwoTipsHostComponent);
    fixture.detectChanges();
    const triggers = fixture.nativeElement.querySelectorAll(
      'button.trigger',
    ) as NodeListOf<HTMLButtonElement>;
    const panels = (): NodeListOf<HTMLElement> => fixture.nativeElement.querySelectorAll('.panel');

    triggers[0].click();
    fixture.detectChanges();
    expect(panels().length).toBe(1);
    expect(panels()[0].textContent).toContain('First.');

    triggers[1].click();
    fixture.detectChanges();
    expect(panels().length).toBe(1);
    expect(panels()[0].textContent).toContain('Second.');
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
