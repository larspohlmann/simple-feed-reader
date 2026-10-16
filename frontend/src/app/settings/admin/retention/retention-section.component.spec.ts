import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../../core/api';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../../shared/toast/toast.service';
import { RetentionSectionComponent } from './retention-section.component';
import { RetentionSettings } from './retention-settings.service';

const BASE = 'https://api.test';
const ENDPOINT = `${BASE}/api/admin/retention`;
const DEFAULTS: RetentionSettings = { entryRetentionDays: 90, socialRetentionDays: 7 };

describe('RetentionSectionComponent', () => {
  let http: HttpTestingController;
  const toastStub = { show: jest.fn() };

  function mount(): ComponentFixture<RetentionSectionComponent> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [RetentionSectionComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
        { provide: ToastService, useValue: toastStub },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(RetentionSectionComponent);
    fixture.detectChanges();
    http.expectOne(ENDPOINT).flush(DEFAULTS);
    fixture.detectChanges();
    return fixture;
  }

  const input = (fixture: ComponentFixture<RetentionSectionComponent>, testId: string) =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`) as HTMLInputElement;

  function type(
    fixture: ComponentFixture<RetentionSectionComponent>,
    testId: string,
    value: string,
  ) {
    const field = input(fixture, testId);
    field.value = value;
    field.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  beforeEach(() => toastStub.show.mockReset());

  afterEach(() => http.verify());

  it('shows both stored windows', () => {
    const fixture = mount();

    expect(input(fixture, 'retention-entry-days').value).toBe('90');
    expect(input(fixture, 'retention-social-days').value).toBe('7');
    expect(fixture.componentInstance.svc.dirty()).toBe(false);
  });

  it('saves an edited window with the other one unchanged, then toasts', () => {
    const fixture = mount();
    type(fixture, 'retention-social-days', '3');
    expect(fixture.componentInstance.svc.dirty()).toBe(true);

    fixture.componentInstance.onSave();
    const request = http.expectOne(ENDPOINT);
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ entryRetentionDays: 90, socialRetentionDays: 3 });
    request.flush({ entryRetentionDays: 90, socialRetentionDays: 3 });
    fixture.detectChanges();

    expect(input(fixture, 'retention-social-days').value).toBe('3');
    expect(fixture.componentInstance.svc.dirty()).toBe(false);
    expect(toastStub.show).toHaveBeenCalledWith({
      message: 'Retention saved',
      durationMs: CONFIRMATION_DURATION_MS,
    });
  });

  it('restores the stored window on Reset', () => {
    const fixture = mount();
    type(fixture, 'retention-entry-days', '30');

    fixture.componentInstance.onReset();
    fixture.detectChanges();

    expect(input(fixture, 'retention-entry-days').value).toBe('90');
    expect(fixture.componentInstance.svc.dirty()).toBe(false);
  });

  it('shows a refused save in a banner and keeps the edit', () => {
    const fixture = mount();
    type(fixture, 'retention-entry-days', '0');

    fixture.componentInstance.onSave();
    http
      .expectOne(ENDPOINT)
      .flush(
        { title: 'Validation failed', detail: 'One or more fields are invalid.' },
        { status: 422, statusText: 'Unprocessable Entity' },
      );
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('app-error-banner')).not.toBeNull();
    expect(input(fixture, 'retention-entry-days').value).toBe('0');
  });
});
