import { Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { StepperBarComponent } from './stepper-bar.component';

@Component({
  imports: [StepperBarComponent],
  template: `
    <app-stepper-bar
      [step]="step()"
      [min]="1"
      [max]="5"
      decreaseIcon="zoom_in_map"
      increaseIcon="zoom_out_map"
      decreaseClass="narrower"
      increaseClass="wider"
      groupLabel="Precision"
      decreaseLabel="Narrower"
      increaseLabel="Wider"
      resetLabel="Back to default"
      [readout]="'Step ' + step()"
      (decrease)="events.push('decrease')"
      (increase)="events.push('increase')"
      (resetStep)="events.push('reset')"
    />
  `,
})
class HostComponent {
  readonly step = signal(2);
  readonly events: string[] = [];
}

describe('StepperBarComponent', () => {
  let fixture: ComponentFixture<HostComponent>;

  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [HostComponent] });
    fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
  });

  const element = <T extends HTMLElement>(selector: string): T =>
    (fixture.nativeElement as HTMLElement).querySelector<T>(selector)!;

  function setStep(step: number): void {
    fixture.componentInstance.step.set(step);
    fixture.detectChanges();
  }

  it('fills a quarter at step 2 of 1..5', () => {
    expect(parseFloat(element('.fill').style.getPropertyValue('inline-size'))).toBe(25);
  });

  it('emits decrease, increase and reset from the decrease end, the increase end and the bar', () => {
    element('.narrower').click();
    element('.wider').click();
    element('.bar').click();
    expect(fixture.componentInstance.events).toEqual(['decrease', 'increase', 'reset']);
  });

  it('disables the decrease end at min and the increase end at max', () => {
    setStep(1);
    expect(element<HTMLButtonElement>('.narrower').disabled).toBe(true);
    expect(element<HTMLButtonElement>('.wider').disabled).toBe(false);
    setStep(5);
    expect(element<HTMLButtonElement>('.narrower').disabled).toBe(false);
    expect(element<HTMLButtonElement>('.wider').disabled).toBe(true);
  });

  it('renders the given icons, labels, classes and readout', () => {
    expect(element('.narrower').textContent).toContain('zoom_in_map');
    expect(element('.wider').textContent).toContain('zoom_out_map');
    expect(element('.narrower').getAttribute('title')).toBe('Narrower');
    expect(element('.narrower').getAttribute('aria-label')).toBe('Narrower');
    expect(element('.wider').getAttribute('title')).toBe('Wider');
    expect(element('.bar').getAttribute('title')).toBe('Back to default');
    expect(element('.bar').getAttribute('aria-label')).toBe('Back to default');
    expect(element('[role=group]').getAttribute('aria-label')).toBe('Precision');
    expect(element('output').textContent?.trim()).toBe('Step 2');
  });

  it('keeps the frame structure the sidebar styles rely on', () => {
    expect(element('.seg[role=group] > button.end.narrower')).not.toBeNull();
    expect(element('.seg > button.bar > .track > .fill')).not.toBeNull();
    expect(element('.seg > button.end.wider')).not.toBeNull();
    expect(element('.seg > output.sr-only[aria-live=polite]')).not.toBeNull();
  });

  it('defaults the end classes to decrease and increase', () => {
    const bare = TestBed.createComponent(StepperBarComponent);
    bare.componentRef.setInput('step', 1);
    bare.componentRef.setInput('min', 0);
    bare.componentRef.setInput('max', 2);
    for (const name of [
      'decreaseIcon',
      'increaseIcon',
      'groupLabel',
      'decreaseLabel',
      'increaseLabel',
      'resetLabel',
      'readout',
    ]) {
      bare.componentRef.setInput(name, name);
    }
    bare.detectChanges();
    const host = bare.nativeElement as HTMLElement;
    expect(host.querySelector('button.end.decrease')).not.toBeNull();
    expect(host.querySelector('button.end.increase')).not.toBeNull();
  });
});
