import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { SettingsCardComponent } from './settings-card.component';

@Component({
  imports: [SettingsCardComponent],
  template: `
    <app-settings-card [heading]="'Tags'" [description]="desc">
      <p class="projected">body</p>
    </app-settings-card>
  `,
})
class HostComponent {
  desc: string | null = null;
}

describe('SettingsCardComponent', () => {
  async function render(desc: string | null = null) {
    await TestBed.configureTestingModule({ imports: [HostComponent] }).compileComponents();
    const fixture = TestBed.createComponent(HostComponent);
    fixture.componentInstance.desc = desc;
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('renders the heading as a level-2 heading', async () => {
    const el = await render();
    expect(el.querySelector('h2')?.textContent?.trim()).toBe('Tags');
  });

  it('projects its content', async () => {
    const el = await render();
    expect(el.querySelector('.projected')?.textContent).toBe('body');
  });

  it('omits the description element when there is no description', async () => {
    const el = await render(null);
    expect(el.querySelector('.description')).toBeNull();
  });

  it('renders the description when one is given', async () => {
    const el = await render('Group your feeds.');
    expect(el.querySelector('.description')?.textContent?.trim()).toBe('Group your feeds.');
  });
});
