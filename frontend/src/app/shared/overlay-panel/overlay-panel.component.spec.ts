import { TestBed } from '@angular/core/testing';
import { Component, signal } from '@angular/core';
import { OverlayPanelComponent } from './overlay-panel.component';

@Component({
  imports: [OverlayPanelComponent],
  template: `
    <app-overlay-panel heading="Edit tag" [headingLevel]="level()" [fillOnMobile]="fill()">
      <p class="body-probe">body</p>
      <button footer class="footer-probe">Save</button>
    </app-overlay-panel>
  `,
})
class Host {
  readonly level = signal<1 | 2>(2);
  readonly fill = signal(false);
}

describe('OverlayPanelComponent', () => {
  const mount = async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    return fixture;
  };

  it('renders the heading as the panel title', async () => {
    const element: HTMLElement = (await mount()).nativeElement;
    expect(element.querySelector('h2')?.textContent?.trim()).toBe('Edit tag');
  });

  it('projects body content into the scrolling region', async () => {
    const element: HTMLElement = (await mount()).nativeElement;
    expect(element.querySelector('.body .body-probe')).not.toBeNull();
  });

  it('projects footer content into the footer, not the body', async () => {
    const element: HTMLElement = (await mount()).nativeElement;
    expect(element.querySelector('.footer .footer-probe')).not.toBeNull();
    expect(element.querySelector('.body .footer-probe')).toBeNull();
  });

  it('labels the panel with its heading for assistive tech', async () => {
    const element: HTMLElement = (await mount()).nativeElement;
    const panel = element.querySelector('.panel') as HTMLElement;
    const heading = element.querySelector('h2') as HTMLElement;
    expect(panel.getAttribute('aria-labelledby')).toBe(heading.id);
    expect(heading.id).toBeTruthy();
  });

  // #325: a dialog is a card at every width; only a surface that opts in gets
  // the full-screen phone layout, and the class is the sole hook the stylesheet
  // keys that off.
  it('stays a card unless it opts into the full-screen phone layout', async () => {
    const fixture = await mount();
    const panel = fixture.nativeElement.querySelector('.panel') as HTMLElement;
    expect(panel.classList).not.toContain('fill-mobile');

    fixture.componentInstance.fill.set(true);
    fixture.detectChanges();
    expect(panel.classList).toContain('fill-mobile');
  });

  it('renders the heading at the requested level, still labelling the panel', async () => {
    const fixture = await mount();
    const element: HTMLElement = fixture.nativeElement;
    expect(element.querySelector('h1')).toBeNull();

    fixture.componentInstance.level.set(1);
    fixture.detectChanges();

    const heading = element.querySelector('h1') as HTMLElement;
    expect(heading.textContent?.trim()).toBe('Edit tag');
    expect(element.querySelector('h2')).toBeNull();
    expect((element.querySelector('.panel') as HTMLElement).getAttribute('aria-labelledby')).toBe(
      heading.id,
    );
  });
});
