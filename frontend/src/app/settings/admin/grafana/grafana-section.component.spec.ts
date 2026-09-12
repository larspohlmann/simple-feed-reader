import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { API_BASE_URL } from '../../../core/api';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../../shared/toast/toast.service';
import { GrafanaSectionComponent } from './grafana-section.component';
import { GrafanaSettingsState } from './grafana-settings.service';

const BASE = 'https://api.test';
const ENDPOINT = `${BASE}/api/admin/grafana`;

function state(over: Partial<GrafanaSettingsState> = {}): GrafanaSettingsState {
  return {
    lokiPushUrl: null,
    lokiPushUrlDefault: 'http://loki:3100',
    lokiPushUrlEffective: 'http://loki:3100',
    lokiUsername: null,
    grafanaUrl: null,
    grafanaUrlDefault: '',
    grafanaUrlEffective: null,
    hasToken: false,
    tokenHint: '',
    containerPresent: true,
    pyroscopePushUrl: null,
    pyroscopePushUrlDefault: 'http://pyroscope:4040',
    pyroscopePushUrlEffective: 'http://pyroscope:4040',
    profilingEnabled: false,
    profilingContainerPresent: true,
    profilerAvailable: true,
    ...over,
  };
}

describe('GrafanaSectionComponent', () => {
  let http: HttpTestingController;
  const toastStub = { show: jest.fn() };

  function mount(
    initial: GrafanaSettingsState = state(),
  ): ComponentFixture<GrafanaSectionComponent> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [GrafanaSectionComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: BASE },
        { provide: ToastService, useValue: toastStub },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(GrafanaSectionComponent);
    fixture.detectChanges();
    http.expectOne(ENDPOINT).flush(initial);
    fixture.detectChanges();
    return fixture;
  }

  const lokiPushUrlInput = (fixture: ComponentFixture<GrafanaSectionComponent>): HTMLInputElement =>
    fixture.nativeElement.querySelector('[data-testid="grafana-loki-push-url"]');

  const lokiUsernameInput = (
    fixture: ComponentFixture<GrafanaSectionComponent>,
  ): HTMLInputElement =>
    fixture.nativeElement.querySelector('[data-testid="grafana-loki-username"]');

  const tokenInput = (fixture: ComponentFixture<GrafanaSectionComponent>): HTMLInputElement =>
    fixture.nativeElement.querySelector('[data-testid="grafana-token"]');

  const grafanaUrlInput = (fixture: ComponentFixture<GrafanaSectionComponent>): HTMLInputElement =>
    fixture.nativeElement.querySelector('[data-testid="grafana-url"]');

  const removeTokenButton = (
    fixture: ComponentFixture<GrafanaSectionComponent>,
  ): HTMLButtonElement | null =>
    fixture.nativeElement.querySelector('[data-testid="grafana-remove-token"] button');

  const openLink = (fixture: ComponentFixture<GrafanaSectionComponent>): HTMLAnchorElement | null =>
    fixture.nativeElement.querySelector('.open-link');

  const profilingToggle = (fixture: ComponentFixture<GrafanaSectionComponent>): HTMLInputElement =>
    fixture.nativeElement.querySelector('[data-testid="grafana-profiling-toggle"] input');

  const pyroscopePushUrlInput = (
    fixture: ComponentFixture<GrafanaSectionComponent>,
  ): HTMLInputElement =>
    fixture.nativeElement.querySelector('[data-testid="grafana-pyroscope-push-url"]');

  beforeEach(() => {
    toastStub.show.mockReset();
  });

  afterEach(() => http.verify());

  it('renders all three groups once state loads', () => {
    const fixture = mount();

    const groups = fixture.nativeElement.querySelectorAll('app-settings-group');
    expect(groups.length).toBe(3);
    expect(fixture.nativeElement.textContent).toContain('Log shipping');
    expect(fixture.nativeElement.textContent).toContain('Viewing');
    expect(fixture.nativeElement.textContent).toContain('Profiling');
  });

  it('shows the local-container hint with the effective push URL when a container is present', () => {
    const fixture = mount(
      state({ lokiPushUrlEffective: 'http://loki:3100', containerPresent: true }),
    );

    expect(fixture.nativeElement.textContent).toContain('Local container: http://loki:3100');
  });

  it('hides the local-container hint when no container is present', () => {
    const fixture = mount(state({ containerPresent: false, profilingContainerPresent: false }));

    expect(fixture.nativeElement.textContent).not.toContain('Local container:');
  });

  it('marks the save bar dirty when the Loki push URL is edited', () => {
    const fixture = mount();

    lokiPushUrlInput(fixture).value = 'https://loki.example.com';
    lokiPushUrlInput(fixture).dispatchEvent(new Event('input'));
    fixture.detectChanges();

    expect(fixture.componentInstance.svc.dirty()).toBe(true);
  });

  it('saves an edited URL via the explicit Save', () => {
    const fixture = mount();

    lokiPushUrlInput(fixture).value = 'https://loki.example.com';
    lokiPushUrlInput(fixture).dispatchEvent(new Event('input'));
    lokiUsernameInput(fixture).value = 'sam';
    lokiUsernameInput(fixture).dispatchEvent(new Event('input'));
    fixture.detectChanges();

    const saveButton = fixture.nativeElement.querySelector('.savebar button.primary');
    saveButton.click();
    fixture.detectChanges();

    const put = http.expectOne(ENDPOINT);
    expect(put.request.method).toBe('PUT');
    expect(put.request.body).toEqual({
      lokiPushUrl: 'https://loki.example.com',
      lokiUsername: 'sam',
      grafanaUrl: null,
      token: null,
      removeToken: false,
      pyroscopePushUrl: null,
      profilingEnabled: false,
    });

    put.flush(state({ lokiPushUrl: 'https://loki.example.com', lokiUsername: 'sam' }));
    expect(fixture.componentInstance.svc.dirty()).toBe(false);
  });

  it('sends null, not an empty string, when a URL field is cleared', () => {
    const fixture = mount(state({ lokiPushUrl: 'https://loki.example.com' }));

    lokiPushUrlInput(fixture).value = '';
    lokiPushUrlInput(fixture).dispatchEvent(new Event('input'));
    fixture.detectChanges();

    fixture.componentInstance.onSave();

    const put = http.expectOne(ENDPOINT);
    expect(put.request.body.lokiPushUrl).toBeNull();
    put.flush(state({ lokiPushUrl: null }));
  });

  it('confirms a save with a toast that dismisses itself', () => {
    const fixture = mount();

    lokiUsernameInput(fixture).value = 'sam';
    lokiUsernameInput(fixture).dispatchEvent(new Event('input'));
    fixture.detectChanges();

    fixture.componentInstance.onSave();
    http.expectOne(ENDPOINT).flush(state({ lokiUsername: 'sam' }));
    fixture.detectChanges();

    expect(toastStub.show).toHaveBeenCalledWith({
      message: 'Grafana settings saved',
      durationMs: CONFIRMATION_DURATION_MS,
    });
  });

  it('never seeds the token field, and shows the stored-token hint when one exists', () => {
    const fixture = mount(state({ hasToken: true, tokenHint: 'stored key ends …wxyz' }));

    expect(tokenInput(fixture).value).toBe('');
    expect(tokenInput(fixture).type).toBe('password');
    expect(fixture.nativeElement.textContent).toContain('stored key ends …wxyz');
  });

  it('hides the token hint and Remove button when no token is stored', () => {
    const fixture = mount(state({ hasToken: false }));

    expect(removeTokenButton(fixture)).toBeNull();
  });

  it('removes the stored token via svc.removeToken() when Remove is clicked', () => {
    const fixture = mount(state({ hasToken: true }));
    const removeSpy = jest.spyOn(fixture.componentInstance.svc, 'removeToken');

    removeTokenButton(fixture)?.click();
    fixture.detectChanges();

    expect(removeSpy).toHaveBeenCalled();

    const put = http.expectOne(ENDPOINT);
    expect(put.request.body.removeToken).toBe(true);
    put.flush(state({ hasToken: false }));
  });

  it('disables the Remove-token button while the draft is dirty', () => {
    const fixture = mount(state({ hasToken: true }));

    lokiUsernameInput(fixture).value = 'sam';
    lokiUsernameInput(fixture).dispatchEvent(new Event('input'));
    fixture.detectChanges();

    expect(removeTokenButton(fixture)?.disabled).toBe(true);
  });

  it('shows the Grafana URL effective hint and a link-out to it', () => {
    const fixture = mount(state({ grafanaUrlEffective: 'https://grafana.example.com' }));

    expect(fixture.nativeElement.textContent).toContain('Currently: https://grafana.example.com');
    expect(openLink(fixture)?.getAttribute('href')).toBe('https://grafana.example.com');
  });

  it('hides the link-out when there is no effective Grafana URL', () => {
    const fixture = mount(state({ grafanaUrlEffective: null }));

    expect(openLink(fixture)).toBeNull();
  });

  it('restores the last-saved values on Reset', () => {
    const fixture = mount(state({ lokiUsername: 'sam' }));

    lokiUsernameInput(fixture).value = 'other';
    lokiUsernameInput(fixture).dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(lokiUsernameInput(fixture).value).toBe('other');

    fixture.componentInstance.onReset();
    fixture.detectChanges();

    expect(lokiUsernameInput(fixture).value).toBe('sam');
    expect(fixture.componentInstance.svc.dirty()).toBe(false);
  });

  it('keeps a cleared username cleared rather than reverting to server truth', () => {
    const fixture = mount(state({ lokiUsername: 'sam' }));

    lokiUsernameInput(fixture).value = '';
    lokiUsernameInput(fixture).dispatchEvent(new Event('input'));
    fixture.detectChanges();

    expect(fixture.componentInstance.svc.draft()).toEqual({ lokiUsername: '' });
  });

  it('uses the URL defaults as placeholders', () => {
    const fixture = mount(
      state({ lokiPushUrlDefault: 'http://loki:3100', grafanaUrlDefault: 'http://grafana:3000' }),
    );

    expect(lokiPushUrlInput(fixture).placeholder).toBe('http://loki:3100');
    expect(grafanaUrlInput(fixture).placeholder).toBe('http://grafana:3000');
  });

  it('toggling profiling PUTs the full body with profilingEnabled: true and stays clean', () => {
    const fixture = mount();

    profilingToggle(fixture).checked = true;
    profilingToggle(fixture).dispatchEvent(new Event('change'));
    fixture.detectChanges();

    const put = http.expectOne(ENDPOINT);
    expect(put.request.method).toBe('PUT');
    expect(put.request.body).toEqual({
      lokiPushUrl: null,
      lokiUsername: null,
      grafanaUrl: null,
      token: null,
      removeToken: false,
      pyroscopePushUrl: null,
      profilingEnabled: true,
    });

    put.flush(state({ profilingEnabled: true }));
    fixture.detectChanges();

    expect(fixture.componentInstance.svc.dirty()).toBe(false);
  });

  it('editing the Pyroscope URL marks dirty and Save sends pyroscopePushUrl', () => {
    const fixture = mount();

    pyroscopePushUrlInput(fixture).value = 'https://pyroscope.example.com';
    pyroscopePushUrlInput(fixture).dispatchEvent(new Event('input'));
    fixture.detectChanges();

    expect(fixture.componentInstance.svc.dirty()).toBe(true);

    fixture.componentInstance.onSave();

    const put = http.expectOne(ENDPOINT);
    expect(put.request.body.pyroscopePushUrl).toBe('https://pyroscope.example.com');
    put.flush(state({ pyroscopePushUrl: 'https://pyroscope.example.com' }));
  });

  it('shows the local-container hint with the effective Pyroscope push URL when a container is present', () => {
    const fixture = mount(
      state({
        profilingContainerPresent: true,
        pyroscopePushUrlEffective: 'http://pyroscope:4040',
      }),
    );

    expect(fixture.nativeElement.textContent).toContain('Local container: http://pyroscope:4040');
  });

  it('disables the profiling toggle and shows the unavailable hint when the profiler is missing', () => {
    const fixture = mount(state({ profilerAvailable: false }));

    expect(profilingToggle(fixture).disabled).toBe(true);
    expect(fixture.nativeElement.textContent).toContain(
      'The profiler extension is not installed on this host, so profiling cannot run here.',
    );
  });

  it('uses the Pyroscope URL default as the placeholder', () => {
    const fixture = mount(state({ pyroscopePushUrlDefault: 'http://pyroscope:4040' }));

    expect(pyroscopePushUrlInput(fixture).placeholder).toBe('http://pyroscope:4040');
  });
});
