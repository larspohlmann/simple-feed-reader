import { Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { selectionQueryParams } from '../../query/query';
import { SidebarGroupRowComponent } from './sidebar-group-row.component';
import { SidebarSubtagPillComponent } from './sidebar-subtag-pill.component';

@Component({
  imports: [SidebarGroupRowComponent, SidebarSubtagPillComponent],
  template: `
    <div class="tag">
      <app-sidebar-group-row
        [link]="link"
        label="Politics"
        toggleLabel="Toggle Politics"
        glyph="gavel"
        [count]="count()"
        [active]="active()"
        [expanded]="expanded()"
        (toggled)="toggles = toggles + 1"
      >
        <app-sidebar-subtag-pill [count]="subtagCount()" />
        <button class="dots" type="button">menu</button>
      </app-sidebar-group-row>
    </div>
  `,
})
class HostComponent {
  readonly link = selectionQueryParams({ tag: 4 });
  readonly count = signal(3);
  readonly subtagCount = signal(0);
  readonly active = signal(false);
  readonly expanded = signal(false);
  toggles = 0;
}

function mount(): ComponentFixture<HostComponent> {
  TestBed.configureTestingModule({
    imports: [HostComponent, provideTranslocoTesting()],
    providers: [provideRouter([{ path: '**', children: [] }])],
  });
  const fixture = TestBed.createComponent(HostComponent);
  fixture.detectChanges();
  return fixture;
}

const root = (fixture: ComponentFixture<HostComponent>): HTMLElement =>
  fixture.nativeElement as HTMLElement;

describe('SidebarGroupRowComponent', () => {
  it('links its glyph, label and count to the group list', () => {
    const link = root(mount()).querySelector('a.nav.grow') as HTMLAnchorElement;

    expect(link.getAttribute('href')).toContain('tag=4');
    expect(link.querySelector('.lead app-tag-glyph')).not.toBeNull();
    expect(link.textContent).toContain('Politics');
    expect(link.querySelector('.count')?.textContent?.trim()).toBe('3');
  });

  it('shows no count when nothing is unread', () => {
    const fixture = mount();
    fixture.componentInstance.count.set(0);
    fixture.detectChanges();

    expect(root(fixture).querySelector('.count')).toBeNull();
  });

  it('marks the link active', () => {
    const fixture = mount();
    fixture.componentInstance.active.set(true);
    fixture.detectChanges();

    expect(root(fixture).querySelector('a.nav.active')).not.toBeNull();
  });

  it('places projected content between the link and the chevron', () => {
    const row = root(mount()).querySelector('app-sidebar-group-row') as HTMLElement;

    expect([...row.children].map((child) => child.className)).toEqual([
      'nav grow',
      'dots',
      'chevzone',
    ]);
  });

  it('reports a chevron press and shows the expanded state', () => {
    const fixture = mount();
    const chevron = root(fixture).querySelector('.chevzone') as HTMLButtonElement;

    expect(chevron.getAttribute('aria-expanded')).toBe('false');
    expect(chevron.getAttribute('aria-label')).toBe('Toggle Politics');
    chevron.click();
    fixture.componentInstance.expanded.set(true);
    fixture.detectChanges();

    expect(fixture.componentInstance.toggles).toBe(1);
    expect(chevron.getAttribute('aria-expanded')).toBe('true');
    expect(chevron.textContent).toContain('expand_more');
  });

  it('places a projected subtag pill after the name, keeping the chevron', () => {
    const fixture = mount();

    fixture.componentInstance.subtagCount.set(4);
    fixture.detectChanges();

    const pill = root(fixture).querySelector('.name + app-sidebar-subtag-pill');
    expect(pill?.textContent).toContain('4');
    expect(root(fixture).querySelector('.chevzone')?.textContent).toContain('chevron_right');
  });

  it('marks its host current while active and open while expanded', () => {
    const fixture = mount();
    const row = root(fixture).querySelector('app-sidebar-group-row') as HTMLElement;
    expect(row.classList).not.toContain('current');
    expect(row.classList).not.toContain('open');

    fixture.componentInstance.active.set(true);
    fixture.componentInstance.expanded.set(true);
    fixture.detectChanges();

    expect(row.classList).toContain('current');
    expect(row.classList).toContain('open');
  });
});
