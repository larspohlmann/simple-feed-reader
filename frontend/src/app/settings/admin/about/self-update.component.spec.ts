import { HttpErrorResponse } from '@angular/common/http';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Observable, Subject, of, throwError } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { AdminApi } from '../../../admin/admin-api';
import { SelfUpdatePhase, SelfUpdateStatusDto } from '../../../admin/admin.models';
import { LatestRelease, VersionService } from '../../../core/version.service';
import { ConfirmService } from '../../../shared/confirm-dialog/confirm.service';
import { SELF_UPDATE_POLL_INTERVAL_MS, SelfUpdateComponent } from './self-update.component';

function status(
  phase: SelfUpdatePhase,
  overrides: Partial<SelfUpdateStatusDto> = {},
): SelfUpdateStatusDto {
  return {
    updaterPresent: true,
    phase,
    startedAt: null,
    finishedAt: null,
    exitCode: null,
    log: '',
    ...overrides,
  };
}

describe('SelfUpdateComponent', () => {
  const updateStatus = jest.fn<Observable<SelfUpdateStatusDto>, []>();
  const requestUpdate = jest.fn<Observable<SelfUpdateStatusDto>, []>();
  const ask = jest.fn<Observable<boolean>, [unknown]>();
  const load = jest.fn();
  const updateAvailable = signal(false);
  const latest = signal<LatestRelease | null>(null);

  function mount(): ComponentFixture<SelfUpdateComponent> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: AdminApi, useValue: { updateStatus, requestUpdate } },
        { provide: VersionService, useValue: { load, updateAvailable, latest } },
        { provide: ConfirmService, useValue: { ask } },
      ],
    });
    const fixture = TestBed.createComponent(SelfUpdateComponent);
    fixture.detectChanges();
    return fixture;
  }

  function element(fixture: ComponentFixture<SelfUpdateComponent>): HTMLElement {
    return fixture.nativeElement;
  }

  function button(fixture: ComponentFixture<SelfUpdateComponent>): HTMLButtonElement | null {
    return element(fixture).querySelector('button');
  }

  function offerUpdate(): void {
    updateAvailable.set(true);
    latest.set({ version: 'v9.9.9', notesUrl: 'https://example.test/notes' });
  }

  function advancePoll(fixture: ComponentFixture<SelfUpdateComponent>): void {
    jest.advanceTimersByTime(SELF_UPDATE_POLL_INTERVAL_MS);
    fixture.detectChanges();
  }

  beforeEach(() => {
    jest.useFakeTimers();
    updateStatus.mockReset();
    requestUpdate.mockReset();
    ask.mockReset();
    load.mockReset();
    updateAvailable.set(false);
    latest.set(null);
  });

  afterEach(() => jest.useRealTimers());

  it('points to the host script when the updater is not installed', () => {
    offerUpdate();
    updateStatus.mockReturnValue(of(status('idle', { updaterPresent: false })));
    const fixture = mount();

    expect(element(fixture).querySelector('.manual')?.textContent).toContain('./scripts/update.sh');
    expect(button(fixture)).toBeNull();
  });

  it('offers the newer release as an update', () => {
    offerUpdate();
    updateStatus.mockReturnValue(of(status('idle')));
    const fixture = mount();

    expect(button(fixture)?.textContent).toContain('Update to v9.9.9');
  });

  it('asks before requesting the update', () => {
    offerUpdate();
    updateStatus.mockReturnValue(of(status('idle')));
    ask.mockReturnValue(of(true));
    requestUpdate.mockReturnValue(of(status('requested')));
    const fixture = mount();

    button(fixture)?.click();

    expect(ask).toHaveBeenCalledWith(
      expect.objectContaining({
        title: 'Update this install?',
        message: expect.stringContaining('database dump'),
        confirmLabel: 'Update',
      }),
    );
    expect(requestUpdate).toHaveBeenCalledTimes(1);
  });

  it('requests nothing when the admin backs out', () => {
    offerUpdate();
    updateStatus.mockReturnValue(of(status('idle')));
    ask.mockReturnValue(of(false));
    const fixture = mount();

    button(fixture)?.click();

    expect(ask).toHaveBeenCalledTimes(1);
    expect(requestUpdate).not.toHaveBeenCalled();
  });

  it('renders nothing when the install is current', () => {
    updateStatus.mockReturnValue(of(status('idle')));
    const fixture = mount();

    expect(updateStatus).toHaveBeenCalledTimes(1);
    expect(element(fixture).querySelector('.self-update')).toBeNull();
    expect(element(fixture).textContent?.trim()).toBe('');
  });

  it('asks only once while a start is under way', () => {
    offerUpdate();
    updateStatus.mockReturnValue(of(status('idle')));
    const answer = new Subject<boolean>();
    ask.mockReturnValue(answer);
    const pendingRequest = new Subject<SelfUpdateStatusDto>();
    requestUpdate.mockReturnValue(pendingRequest);
    const fixture = mount();

    button(fixture)?.click();
    fixture.detectChanges();
    button(fixture)?.click();
    answer.next(true);
    fixture.detectChanges();
    button(fixture)?.click();

    expect(ask).toHaveBeenCalledTimes(1);
    expect(requestUpdate).toHaveBeenCalledTimes(1);
  });

  it('offers the start again after the admin backs out', () => {
    offerUpdate();
    updateStatus.mockReturnValue(of(status('idle')));
    ask.mockReturnValue(of(false));
    const fixture = mount();

    button(fixture)?.click();
    fixture.detectChanges();
    button(fixture)?.click();

    expect(ask).toHaveBeenCalledTimes(2);
  });

  it('retries the first status read until one arrives', () => {
    offerUpdate();
    updateStatus
      .mockReturnValueOnce(throwError(() => new HttpErrorResponse({ status: 502 })))
      .mockReturnValue(of(status('idle')));
    const fixture = mount();
    expect(button(fixture)).toBeNull();

    advancePoll(fixture);
    expect(button(fixture)?.textContent).toContain('Update to v9.9.9');

    advancePoll(fixture);
    expect(updateStatus).toHaveBeenCalledTimes(2);
  });

  it('does not re-read the release for an update that finished before the page opened', () => {
    updateStatus.mockReturnValue(of(status('succeeded')));
    const fixture = mount();

    expect(element(fixture).textContent).toContain('Update finished.');
    expect(load).not.toHaveBeenCalled();
  });

  it('reports a failed update that left no exit code', () => {
    updateStatus.mockReturnValue(of(status('failed', { exitCode: null })));
    const fixture = mount();

    expect(element(fixture).querySelector('.phase')?.textContent?.trim()).toBe(
      'The update failed.',
    );
  });

  it('shows a running update with its log and polls again', () => {
    offerUpdate();
    updateStatus.mockReturnValue(of(status('running', { log: 'Pulling images' })));
    const fixture = mount();

    expect(element(fixture).textContent).toContain('Updating…');
    expect(element(fixture).querySelector('pre.log')?.textContent).toContain('Pulling images');
    expect(button(fixture)).toBeNull();

    advancePoll(fixture);

    expect(updateStatus).toHaveBeenCalledTimes(2);
  });

  it('stops polling once nothing is in progress', () => {
    updateStatus.mockReturnValue(of(status('idle')));
    const fixture = mount();

    advancePoll(fixture);

    expect(updateStatus).toHaveBeenCalledTimes(1);
  });

  it('polls a requested update after the request', () => {
    offerUpdate();
    updateStatus.mockReturnValue(of(status('idle')));
    ask.mockReturnValue(of(true));
    requestUpdate.mockReturnValue(of(status('requested')));
    const fixture = mount();

    button(fixture)?.click();
    fixture.detectChanges();
    expect(element(fixture).textContent).toContain('Update requested');

    advancePoll(fixture);

    expect(updateStatus).toHaveBeenCalledTimes(2);
  });

  it('re-reads the release once the update finishes', () => {
    updateStatus
      .mockReturnValueOnce(of(status('running')))
      .mockReturnValue(of(status('succeeded')));
    const fixture = mount();

    advancePoll(fixture);
    advancePoll(fixture);

    expect(element(fixture).textContent).toContain('Update finished.');
    expect(load).toHaveBeenCalledTimes(1);
  });

  it('reports a failed update with its exit code and offers it again', () => {
    offerUpdate();
    updateStatus.mockReturnValue(of(status('failed', { exitCode: 3, log: 'build failed' })));
    const fixture = mount();

    expect(element(fixture).textContent).toContain('The update failed (exit code 3).');
    expect(element(fixture).querySelector('pre.log')?.textContent).toContain('build failed');
    expect(button(fixture)?.textContent).toContain('Update to v9.9.9');
  });

  it('keeps the last status and polls on while the API is down', () => {
    updateStatus
      .mockReturnValueOnce(of(status('running', { log: 'Rebuilding' })))
      .mockReturnValueOnce(throwError(() => new HttpErrorResponse({ status: 502 })))
      .mockReturnValue(of(status('running', { log: 'Rebuilding' })));
    const fixture = mount();

    advancePoll(fixture);
    expect(element(fixture).querySelector('pre.log')?.textContent).toContain('Rebuilding');

    advancePoll(fixture);
    expect(updateStatus).toHaveBeenCalledTimes(3);
  });

  it('shows why a request was refused and re-reads the status', () => {
    offerUpdate();
    updateStatus.mockReturnValueOnce(of(status('idle'))).mockReturnValue(of(status('running')));
    ask.mockReturnValue(of(true));
    requestUpdate.mockReturnValue(
      throwError(
        () =>
          new HttpErrorResponse({
            status: 409,
            error: {
              type: 'update_in_progress',
              title: 'Conflict',
              detail: 'An update is already running.',
            },
          }),
      ),
    );
    const fixture = mount();

    button(fixture)?.click();
    fixture.detectChanges();

    expect(element(fixture).querySelector('app-error-banner')?.textContent).toContain(
      'An update is already running.',
    );
    expect(updateStatus).toHaveBeenCalledTimes(2);
    expect(element(fixture).textContent).toContain('Updating…');
  });

  it('drops the refusal once the phase moves on', () => {
    offerUpdate();
    updateStatus
      .mockReturnValueOnce(of(status('idle')))
      .mockReturnValueOnce(of(status('running')))
      .mockReturnValue(of(status('succeeded')));
    ask.mockReturnValue(of(true));
    requestUpdate.mockReturnValue(
      throwError(
        () =>
          new HttpErrorResponse({
            status: 409,
            error: { type: 'update_in_progress', title: 'Conflict', detail: 'Already running.' },
          }),
      ),
    );
    const fixture = mount();

    button(fixture)?.click();
    fixture.detectChanges();
    expect(element(fixture).querySelector('app-error-banner')).not.toBeNull();

    advancePoll(fixture);

    expect(element(fixture).textContent).toContain('Update finished.');
    expect(element(fixture).querySelector('app-error-banner')).toBeNull();
  });
});
