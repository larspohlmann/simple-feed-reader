import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../core/api';
import { profileRun, profileState } from '../../../testing/profile-settings';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { ToastService } from '../../shared/toast/toast.service';
import { ProfileSectionComponent } from './profile-section.component';
import { ProfileRun, ProfileSettingsState } from './profile-settings.service';

const ENDPOINT = '/api/me/ai/profile';

describe('ProfileSectionComponent', () => {
  let http: HttpTestingController;

  function mount(
    state: ProfileSettingsState = profileState(),
    run: ProfileRun = profileRun(),
  ): ComponentFixture<ProfileSectionComponent> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: '' },
        { provide: ToastService, useValue: { show: jest.fn() } },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(ProfileSectionComponent);
    fixture.detectChanges();
    http.expectOne(ENDPOINT).flush(state);
    http.expectOne(`${ENDPOINT}/runs/current`).flush(run);
    fixture.detectChanges();
    return fixture;
  }

  const element = (fixture: ComponentFixture<ProfileSectionComponent>): HTMLElement =>
    fixture.nativeElement as HTMLElement;
  const byTestId = (
    fixture: ComponentFixture<ProfileSectionComponent>,
    id: string,
  ): HTMLElement | null => element(fixture).querySelector(`[data-testid="${id}"]`);
  const generateButton = (fixture: ComponentFixture<ProfileSectionComponent>): HTMLButtonElement =>
    byTestId(fixture, 'generate-now')?.querySelector('button') as HTMLButtonElement;

  afterEach(() => http.verify());

  it('shows the profile with when and by which model it was generated', () => {
    const fixture = mount();

    expect(byTestId(fixture, 'profile-text')?.textContent).toContain(
      'Likes maps and rail history.',
    );
    expect(byTestId(fixture, 'profile-meta')?.textContent).toContain('qwen3-14b');
    expect(byTestId(fixture, 'profile-meta')?.textContent).toContain('llm.example.test');
  });

  it('says there is no profile yet', () => {
    const fixture = mount(
      profileState({ profileText: null, generatedAt: null, generatedBy: null }),
    );

    expect(byTestId(fixture, 'profile-empty')).not.toBeNull();
    expect(byTestId(fixture, 'profile-text')).toBeNull();
  });

  it('asks for a connection instead of offering Generate now when none can build the profile', () => {
    const fixture = mount(profileState({ connection: null, candidates: [] }));

    expect(byTestId(fixture, 'choose-connection')?.textContent).toContain('Choose one');
    expect(byTestId(fixture, 'generate-now')).toBeNull();
  });

  it('starts a run and says the profile is being built', () => {
    const fixture = mount();

    generateButton(fixture).click();
    http
      .expectOne((each) => each.method === 'POST' && each.url === `${ENDPOINT}/runs`)
      .flush(profileRun({ status: 'pending', id: 4, trigger: 'manual' }));
    fixture.detectChanges();

    expect(byTestId(fixture, 'profile-status')?.textContent).toContain('Building your profile');
    expect(generateButton(fixture).disabled).toBe(true);
    fixture.destroy();
  });

  it('shows a refused start', () => {
    const fixture = mount();

    generateButton(fixture).click();
    http
      .expectOne((each) => each.method === 'POST' && each.url === `${ENDPOINT}/runs`)
      .flush(
        { type: 'rate_limited', title: 'Too many', status: 429, detail: 'Try again later.' },
        { status: 429, statusText: 'Too Many Requests' },
      );
    fixture.detectChanges();

    expect(element(fixture).querySelector('app-error-banner')?.textContent).toContain(
      'Try again later.',
    );
  });

  it('shows why the last run failed', () => {
    const fixture = mount(
      profileState(),
      profileRun({ status: 'failed', id: 5, error: 'The AI provider failed: gone' }),
    );

    expect(element(fixture).querySelector('app-error-banner')?.textContent).toContain(
      'The AI provider failed: gone',
    );
  });

  it('says an unchanged scheduled run skipped the model', () => {
    const fixture = mount(
      profileState(),
      profileRun({ status: 'completed', id: 6, outcome: 'unchanged' }),
    );

    expect(byTestId(fixture, 'profile-status')?.textContent).toContain('has not changed');
  });

  it('saves the schedule the moment it changes', () => {
    const fixture = mount();
    const select = byTestId(fixture, 'profile-schedule') as HTMLSelectElement;

    select.value = '168';
    select.dispatchEvent(new Event('change'));

    const request = http.expectOne((each) => each.method === 'PUT' && each.url === ENDPOINT);
    expect(request.request.body).toEqual({
      intervalHours: 168,
      connectionId: null,
      keptCap: 40,
      viewedCap: 80,
    });
    request.flush(profileState({ intervalHours: 168 }));
  });

  it('saves "only manually" as no schedule', () => {
    const fixture = mount();
    const select = byTestId(fixture, 'profile-schedule') as HTMLSelectElement;

    select.value = '';
    select.dispatchEvent(new Event('change'));

    const request = http.expectOne((each) => each.method === 'PUT' && each.url === ENDPOINT);
    expect(request.request.body.intervalHours).toBeNull();
    request.flush(profileState({ intervalHours: null }));
  });

  it('saves the chosen connection the moment it changes', () => {
    const fixture = mount();
    const select = byTestId(fixture, 'profile-connection') as HTMLSelectElement;

    select.value = '7';
    select.dispatchEvent(new Event('change'));

    const request = http.expectOne((each) => each.method === 'PUT' && each.url === ENDPOINT);
    expect(request.request.body.connectionId).toBe(7);
    request.flush(profileState({ connectionId: 7 }));
  });

  it('names an unnamed connection by its host and model', () => {
    const fixture = mount();
    const options = Array.from(
      (byTestId(fixture, 'profile-connection') as HTMLSelectElement).options,
    ).map((option) => option.textContent?.trim());

    expect(options).toEqual([
      'The active connection',
      'Home LLM · qwen3-14b',
      'api.openai.com · gpt-4o',
    ]);
  });

  it('holds a typed cap until Save', () => {
    const fixture = mount();
    const input = byTestId(fixture, 'profile-kept-cap') as HTMLInputElement;

    input.value = '12';
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    http.expectNone((each) => each.method === 'PUT');
    (
      element(fixture).querySelector('app-settings-save-bar button.primary') as HTMLButtonElement
    ).click();

    const request = http.expectOne((each) => each.method === 'PUT' && each.url === ENDPOINT);
    expect(request.request.body.keptCap).toBe(12);
    request.flush(profileState({ keptCap: 12 }));
  });

  it('shows the debug log only when debug mode is on', () => {
    expect(element(mount()).querySelector('app-profile-debug-log')).toBeNull();

    const debugged = mount(profileState({ debugEnabled: true }));
    http.expectOne(`${ENDPOINT}/runs/current/log`).flush({ entries: [] });

    expect(element(debugged).querySelector('app-profile-debug-log')).not.toBeNull();
  });
});
