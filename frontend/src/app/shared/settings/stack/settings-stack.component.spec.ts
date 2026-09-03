import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { SettingsStackComponent } from './settings-stack.component';

@Component({
  imports: [SettingsStackComponent],
  template: `
    <app-settings-stack>
      <div data-first>one</div>
      <div data-second>two</div>
    </app-settings-stack>
  `,
})
class HostComponent {}

/** Stands in for a feature section whose host element is a stack child --
 *  `app-opml-section` and friends, without dragging their dependencies in. */
@Component({
  selector: 'app-fake-section',
  template: `<div class="panel">section</div>`,
})
class FakeSectionComponent {}

@Component({
  imports: [SettingsStackComponent, FakeSectionComponent],
  template: `
    <app-settings-stack>
      <div data-inline>inline group</div>
      <app-fake-section />
    </app-settings-stack>
  `,
})
class MixedHostComponent {}

describe('SettingsStackComponent', () => {
  async function render() {
    await TestBed.configureTestingModule({ imports: [HostComponent] }).compileComponents();
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  async function renderMixed() {
    await TestBed.configureTestingModule({ imports: [MixedHostComponent] }).compileComponents();
    const fixture = TestBed.createComponent(MixedHostComponent);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('projects every child', async () => {
    const el = await render();

    expect(el.querySelector('app-settings-stack [data-first]')?.textContent).toBe('one');
    expect(el.querySelector('app-settings-stack [data-second]')?.textContent).toBe('two');
  });

  // The stack uses a flex `gap`, so children must be direct element children
  // of the host -- the old adjacent-sibling rule died at a component host
  // boundary (#454). A wrapper in this template would silently reintroduce it.
  it('makes a component host a direct child, exactly like an inline element', async () => {
    const el = await renderMixed();
    const stack = el.querySelector('app-settings-stack')!;

    expect(Array.from(stack.children).map((child) => child.tagName.toLowerCase())).toEqual([
      'div',
      'app-fake-section',
    ]);
  });
});
