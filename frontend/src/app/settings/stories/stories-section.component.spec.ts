import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../core/api';
import { storiesState } from '../../../testing/stories-settings';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { ToastService } from '../../shared/toast/toast.service';
import { StoriesSectionComponent } from './stories-section.component';
import { StoriesSettingsState } from './stories-settings.service';

const ENDPOINT = '/api/me/ai/stories';

describe('StoriesSectionComponent', () => {
  let http: HttpTestingController;
  const toast = { show: jest.fn() };

  function mount(
    state: StoriesSettingsState = storiesState(),
  ): ComponentFixture<StoriesSectionComponent> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: '' },
        { provide: ToastService, useValue: toast },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(StoriesSectionComponent);
    fixture.detectChanges();
    http.expectOne(ENDPOINT).flush(state);
    fixture.detectChanges();
    return fixture;
  }

  const byTestId = (fixture: ComponentFixture<StoriesSectionComponent>, id: string): HTMLElement =>
    (fixture.nativeElement as HTMLElement).querySelector(`[data-testid="${id}"]`) as HTMLElement;

  const expectPut = (body: object, answer: StoriesSettingsState): void => {
    const request = http.expectOne((each) => each.method === 'PUT' && each.url === ENDPOINT);
    expect(request.request.body).toEqual(body);
    request.flush(answer);
  };

  beforeEach(() => toast.show.mockClear());
  afterEach(() => http.verify());

  it('renders its group without a stack of its own', () => {
    const fixture = mount();

    expect((fixture.nativeElement as HTMLElement).querySelector('app-settings-stack')).toBeNull();
    expect(byTestId(fixture, 'story-titles')).not.toBeNull();
    expect(byTestId(fixture, 'story-connection')).not.toBeNull();
  });

  it('turning story titles off saves titlesEnabled false', () => {
    const fixture = mount();

    byTestId(fixture, 'story-titles').querySelector('input')!.click();

    expectPut(
      { writingConnectionId: null, titlesEnabled: false },
      storiesState({ titlesEnabled: false }),
    );
    fixture.detectChanges();
    expect(toast.show).toHaveBeenCalledWith(
      expect.objectContaining({ message: 'Story settings saved' }),
    );
  });

  it('choosing a connection saves its id', () => {
    const fixture = mount();
    const select = byTestId(fixture, 'story-connection').querySelector('select')!;

    select.value = '7';
    select.dispatchEvent(new Event('change'));

    expectPut(
      { writingConnectionId: 7, titlesEnabled: true },
      storiesState({ writingConnectionId: 7 }),
    );
  });

  it('disables the connection select while a save is in flight', () => {
    const fixture = mount();
    const select = byTestId(fixture, 'story-connection').querySelector('select')!;

    select.value = '7';
    select.dispatchEvent(new Event('change'));
    fixture.detectChanges();

    expect(select.disabled).toBe(true);
    expectPut(
      { writingConnectionId: 7, titlesEnabled: true },
      storiesState({ writingConnectionId: 7 }),
    );
    fixture.detectChanges();
    expect(select.disabled).toBe(false);
  });

  it('choosing the active connection saves null', () => {
    const fixture = mount(storiesState({ writingConnectionId: 7 }));
    const select = byTestId(fixture, 'story-connection').querySelector('select')!;

    select.value = '';
    select.dispatchEvent(new Event('change'));

    expectPut({ writingConnectionId: null, titlesEnabled: true }, storiesState());
  });

  it('a stored pick that is no candidate shows as unusable and disabled', () => {
    const fixture = mount(storiesState({ writingConnectionId: 99 }));

    const option = byTestId(fixture, 'story-connection').querySelector(
      '[data-testid="connection-unusable"]',
    ) as HTMLOptionElement;
    expect(option.disabled).toBe(true);
    expect(option.textContent).toContain('A connection that can no longer write text');
  });

  it('offers no unusable option for a stored pick that is a candidate', () => {
    const fixture = mount(storiesState({ writingConnectionId: 7 }));

    expect(
      byTestId(fixture, 'story-connection').querySelector('[data-testid="connection-unusable"]'),
    ).toBeNull();
  });

  it('shows a rejected pick as the server explains it', () => {
    const fixture = mount();
    const select = byTestId(fixture, 'story-connection').querySelector('select')!;
    select.value = '7';
    select.dispatchEvent(new Event('change'));

    http
      .expectOne((each) => each.method === 'PUT')
      .flush(
        { type: 'about:blank', title: 'Rejected', detail: 'That connection cannot write text.' },
        {
          status: 422,
          statusText: 'Unprocessable',
          headers: { 'Content-Type': 'application/problem+json' },
        },
      );
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).textContent).toContain(
      'That connection cannot write text.',
    );
  });
});
