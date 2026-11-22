import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { SidebarNotificationsBellComponent } from './sidebar-notifications-bell.component';

describe('SidebarNotificationsBellComponent', () => {
  let fixture: ComponentFixture<SidebarNotificationsBellComponent>;

  const host = (): HTMLElement => fixture.nativeElement as HTMLElement;

  const render = (count: number, active = false): HTMLElement => {
    fixture.componentRef.setInput('network', 'bluesky');
    fixture.componentRef.setInput('count', count);
    fixture.componentRef.setInput('active', active);
    fixture.detectChanges();
    return host();
  };

  const label = (count: number): string | null | undefined =>
    render(count).querySelector('a.bell')?.getAttribute('aria-label');

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [SidebarNotificationsBellComponent, provideTranslocoTesting()],
      providers: [provideRouter([])],
    });
    fixture = TestBed.createComponent(SidebarNotificationsBellComponent);
  });

  it("links to the network's notifications", () => {
    const link = render(0).querySelector('a.bell');
    expect(link?.getAttribute('href')).toBe('/?view=notifications&network=bluesky');
    expect(link?.querySelector('app-icon')?.textContent?.trim()).toBe('notifications');
  });

  it('shows the unseen count as a badge', () => {
    expect(render(4).querySelector('.badge')?.textContent?.trim()).toBe('4');
  });

  it('shows no badge at zero', () => {
    expect(render(0).querySelector('.badge')).toBeNull();
  });

  it('caps the badge at 99+', () => {
    expect(render(120).querySelector('.badge')?.textContent?.trim()).toBe('99+');
  });

  it('names the network and the unseen count', () => {
    expect(label(0)).toBe('Bluesky notifications');
    expect(label(1)).toBe('Bluesky notifications, 1 unseen');
    expect(label(4)).toBe('Bluesky notifications, 4 unseen');
  });

  it('marks itself current', () => {
    expect(render(0, true).classList.contains('current')).toBe(true);
    expect(render(0, false).classList.contains('current')).toBe(false);
  });
});
