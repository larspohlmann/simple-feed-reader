import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../../core/api';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { QueueDepthDto } from '../../../admin/admin.models';
import { QueuesSectionComponent } from './queues-section.component';

const BASE = 'https://api.test';
const ENDPOINT = `${BASE}/api/admin/queues`;

describe('QueuesSectionComponent', () => {
  let http: HttpTestingController;

  function mount(): ComponentFixture<QueuesSectionComponent> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [QueuesSectionComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(QueuesSectionComponent);
    fixture.detectChanges();
    return fixture;
  }

  function respond(queues: QueueDepthDto[]): void {
    http.expectOne(ENDPOINT).flush({ queues });
  }

  afterEach(() => {
    http.verify();
    jest.useRealTimers();
  });

  it('lists every queue with its waiting count', () => {
    const fixture = mount();
    respond([
      { queue: 'transcripts', waiting: 4, oldestQueuedAt: '2026-10-28T07:00:00+00:00' },
      { queue: 'due_feeds', waiting: 0, oldestQueuedAt: null },
    ]);
    fixture.detectChanges();

    const rows = fixture.nativeElement.querySelectorAll('tbody tr');
    expect(rows).toHaveLength(2);
    expect(rows[0].querySelector('.waiting').textContent.trim()).toBe('4');
    expect(rows[1].querySelector('.oldest').textContent.trim()).toBe('—');
  });

  it('lists the longest queue first and keeps the API order among ties', () => {
    const fixture = mount();
    respond([
      { queue: 'transcripts', waiting: 1, oldestQueuedAt: null },
      { queue: 'due_feeds', waiting: 52, oldestQueuedAt: null },
      { queue: 'failed_messages', waiting: 0, oldestQueuedAt: null },
      { queue: 'image_verification', waiting: 52, oldestQueuedAt: null },
    ]);
    fixture.detectChanges();

    const names = Array.from(
      fixture.nativeElement.querySelectorAll('tbody th[scope=row]') as NodeListOf<HTMLElement>,
    ).map((cell) => cell.textContent?.trim());
    expect(names).toEqual([
      'Feeds due for refresh',
      'Image verification',
      'Transcripts',
      'Failed worker messages',
    ]);
  });

  it('shows the age of the oldest waiting item', () => {
    jest.useFakeTimers({ now: new Date('2026-10-28T10:00:00Z') });
    const fixture = mount();
    respond([{ queue: 'transcripts', waiting: 1, oldestQueuedAt: '2026-10-28T07:00:00+00:00' }]);
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('tbody .oldest').textContent.trim()).toBe(
      '3 hr. ago',
    );
  });

  it('reloads on refresh', () => {
    const fixture = mount();
    respond([]);
    fixture.detectChanges();
    fixture.nativeElement.querySelector('.refresh button').click();
    respond([{ queue: 'transcripts', waiting: 2, oldestQueuedAt: null }]);
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelectorAll('tbody tr')).toHaveLength(1);
  });

  it('shows an error banner when loading fails', () => {
    const fixture = mount();
    http.expectOne(ENDPOINT).flush(null, { status: 500, statusText: 'Server Error' });
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('app-error-banner')).not.toBeNull();
  });
});
