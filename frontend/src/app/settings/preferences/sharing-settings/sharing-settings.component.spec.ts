import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { SharingService } from '../../../core/preferences/sharing.service';
import { SharingSettingsComponent } from './sharing-settings.component';

describe('SharingSettingsComponent', () => {
  beforeEach(() => {
    localStorage.clear();
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [SharingSettingsComponent, provideTranslocoTesting()],
    });
  });

  let fixture: ComponentFixture<SharingSettingsComponent>;

  function mount(): HTMLElement {
    fixture = TestBed.createComponent(SharingSettingsComponent);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  const toggle = (element: HTMLElement, id: string): HTMLInputElement =>
    element.querySelector<HTMLInputElement>(`#${id}`)!;

  it('lists one switch per target, all on by default', () => {
    const element = mount();

    expect(toggle(element, 'sharing-toggle').checked).toBe(true);
    expect(toggle(element, 'share-target-x').checked).toBe(true);
    expect(toggle(element, 'share-target-reddit').checked).toBe(true);
  });

  it('hides a target when its switch goes off', () => {
    const element = mount();

    toggle(element, 'share-target-x').click();

    expect(TestBed.inject(SharingService).hidden().has('x')).toBe(true);
  });

  it('switches sharing off and disables the target switches', () => {
    const element = mount();

    toggle(element, 'sharing-toggle').click();
    fixture.detectChanges();

    expect(TestBed.inject(SharingService).enabled()).toBe(false);
    expect(toggle(element, 'share-target-x').disabled).toBe(true);
  });

  it('reports a failed save', () => {
    TestBed.inject(SharingService).saveFailed.set(true);

    expect(mount().querySelector('app-error-banner')).not.toBeNull();
  });
});
