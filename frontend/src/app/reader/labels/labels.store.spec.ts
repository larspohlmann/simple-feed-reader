import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { LabelsStore } from './labels.store';
import { LabelTally } from './labels.models';

const work: LabelTally = { id: 3, name: 'Work', favoriteCount: 2, keptCount: 1, articleCount: 3 };
const LABELS_URL = 'https://api.test/api/labels';

describe('LabelsStore (#2581)', () => {
  let store: LabelsStore;
  let ctrl: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    store = TestBed.inject(LabelsStore);
    ctrl = TestBed.inject(HttpTestingController);
  });
  afterEach(() => ctrl.verify());

  const answerTallies = (labels: LabelTally[], unlabeled = { favoriteCount: 0, keptCount: 0 }) =>
    ctrl.expectOne(LABELS_URL).flush({ labels, unlabeled });

  it('holds the tallies the reload answers', () => {
    store.reload();
    answerTallies([work], { favoriteCount: 4, keptCount: 5 });

    expect(store.labels()).toEqual([work]);
    expect(store.unlabeled()).toEqual({ favoriteCount: 4, keptCount: 5 });
  });

  it('keeps the last tallies when a reload fails', () => {
    store.reload();
    answerTallies([work]);

    store.reload();
    ctrl.expectOne(LABELS_URL).flush(null, { status: 500, statusText: 'Server Error' });

    expect(store.labels()).toEqual([work]);
  });

  it('creates a label and reloads the tallies', () => {
    let created: unknown = null;

    store.create('Work').subscribe((label) => (created = label));
    const request = ctrl.expectOne({ method: 'POST', url: LABELS_URL });
    expect(request.request.body).toEqual({ name: 'Work' });
    request.flush({ label: { id: 3, name: 'Work' } }, { status: 201, statusText: 'Created' });
    answerTallies([work]);

    expect(created).toEqual({ id: 3, name: 'Work' });
    expect(store.labels()).toEqual([work]);
  });

  it('renames a label and reloads the tallies', () => {
    store.rename(3, 'Job').subscribe();
    const request = ctrl.expectOne({ method: 'PATCH', url: `${LABELS_URL}/3` });
    expect(request.request.body).toEqual({ name: 'Job' });
    request.flush({ label: { id: 3, name: 'Job' } });
    answerTallies([{ ...work, name: 'Job' }]);

    expect(store.labels()[0].name).toBe('Job');
  });

  it('removes a label and reloads the tallies', () => {
    store.remove(3).subscribe();
    ctrl.expectOne({ method: 'DELETE', url: `${LABELS_URL}/3` }).flush(null);
    answerTallies([]);

    expect(store.labels()).toEqual([]);
  });

  it('attaches and detaches a label on an entry without touching the tallies', () => {
    store.attach(9, 3).subscribe();
    ctrl
      .expectOne({ method: 'PUT', url: 'https://api.test/api/entries/9/labels/3' })
      .flush(null, { status: 204, statusText: 'No Content' });
    store.detach(9, 3).subscribe();
    ctrl
      .expectOne({ method: 'DELETE', url: 'https://api.test/api/entries/9/labels/3' })
      .flush(null, { status: 204, statusText: 'No Content' });

    ctrl.expectNone(LABELS_URL);
  });
});
