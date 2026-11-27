import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { LabelsStore } from './labels.store';
import { LabelTally } from './labels.models';
import { EntryRowCache } from '../state/entry-row-cache';
import { SIDEBAR_RELOAD_INTERVAL_MS } from '../state/sidebar-freshness';

const work: LabelTally = { id: 3, name: 'Work', favoriteCount: 2, keptCount: 1, articleCount: 3 };
const LABELS_URL = 'https://api.test/api/labels';

describe('LabelsStore (#2581)', () => {
  let store: LabelsStore;
  let ctrl: HttpTestingController;
  let rows: { renameLabelLocally: jest.Mock; dropLabelLocally: jest.Mock };

  beforeEach(() => {
    rows = { renameLabelLocally: jest.fn(), dropLabelLocally: jest.fn() };
    TestBed.configureTestingModule({
      providers: [
        { provide: EntryRowCache, useValue: rows },
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

  it('orders the tallies by name, accents aside, not in the server’s binary order', () => {
    const later: LabelTally = { ...work, id: 4, name: 'À lire plus tard' };
    store.reload();
    answerTallies([work, later]);

    expect(store.labels().map((label) => label.name)).toEqual(['À lire plus tard', 'Work']);
  });

  it('reloads on a stale tick, not inside the freshness window nor while a load is out', () => {
    jest.useFakeTimers({ now: new Date('2026-11-27T12:00:00Z') });
    try {
      store.reloadIfStale();
      store.reloadIfStale();
      answerTallies([work]);

      store.reloadIfStale();
      ctrl.expectNone(LABELS_URL);

      jest.setSystemTime(Date.now() + SIDEBAR_RELOAD_INTERVAL_MS);
      store.reloadIfStale();
      answerTallies([]);
      expect(store.labels()).toEqual([]);
    } finally {
      jest.useRealTimers();
    }
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
    expect(rows.renameLabelLocally).toHaveBeenCalledWith(3, 'Job');
  });

  it('removes a label and reloads the tallies', () => {
    store.remove(3).subscribe();
    ctrl.expectOne({ method: 'DELETE', url: `${LABELS_URL}/3` }).flush(null);
    answerTallies([]);

    expect(store.labels()).toEqual([]);
    expect(rows.dropLabelLocally).toHaveBeenCalledWith(3);
  });

  it('leaves the rows alone when a rename or delete fails', () => {
    store.rename(3, 'Job').subscribe({ error: jest.fn() });
    ctrl.expectOne(`${LABELS_URL}/3`).flush(null, { status: 409, statusText: 'Conflict' });
    store.remove(3).subscribe({ error: jest.fn() });
    ctrl.expectOne(`${LABELS_URL}/3`).flush(null, { status: 500, statusText: 'Server Error' });

    expect(rows.renameLabelLocally).not.toHaveBeenCalled();
    expect(rows.dropLabelLocally).not.toHaveBeenCalled();
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
