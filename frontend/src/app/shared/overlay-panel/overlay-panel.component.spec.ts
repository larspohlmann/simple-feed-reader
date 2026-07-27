import { TestBed } from '@angular/core/testing';
import { Component } from '@angular/core';
import { OverlayPanelComponent } from './overlay-panel.component';

@Component({
  imports: [OverlayPanelComponent],
  template: `
    <app-overlay-panel heading="Edit tag">
      <p class="body-probe">body</p>
      <button footer class="footer-probe">Save</button>
    </app-overlay-panel>
  `,
})
class Host {}

describe('OverlayPanelComponent', () => {
  const mount = async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    return fixture;
  };

  it('renders the heading as the panel title', async () => {
    const el: HTMLElement = (await mount()).nativeElement;
    expect(el.querySelector('h2')?.textContent?.trim()).toBe('Edit tag');
  });

  it('projects body content into the scrolling region', async () => {
    const el: HTMLElement = (await mount()).nativeElement;
    expect(el.querySelector('.body .body-probe')).not.toBeNull();
  });

  it('projects footer content into the footer, not the body', async () => {
    const el: HTMLElement = (await mount()).nativeElement;
    expect(el.querySelector('.footer .footer-probe')).not.toBeNull();
    expect(el.querySelector('.body .footer-probe')).toBeNull();
  });

  it('labels the panel with its heading for assistive tech', async () => {
    const el: HTMLElement = (await mount()).nativeElement;
    const panel = el.querySelector('.panel') as HTMLElement;
    const heading = el.querySelector('h2') as HTMLElement;
    expect(panel.getAttribute('aria-labelledby')).toBe(heading.id);
    expect(heading.id).toBeTruthy();
  });
});
