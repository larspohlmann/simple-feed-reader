import { anEntry } from '../../../testing/entry-testing';
import { InFlightPatches, valuesBefore } from './in-flight-patches';

describe('InFlightPatches', () => {
  it('knowsAFieldOnTheWireUntilReleased', () => {
    const ledger = new InFlightPatches();

    const release = ledger.add(1, { myVote: 1, score: 11 });

    expect(ledger.onTheWire(1, 'myVote')).toBe(true);
    expect(ledger.onTheWire(1, 'score')).toBe(true);
    expect(ledger.onTheWire(1, 'social')).toBe(false);
    expect(ledger.onTheWire(2, 'myVote')).toBe(false);
    release();
    expect(ledger.onTheWire(1, 'myVote')).toBe(false);
  });

  it('forgetsEverythingOnClear', () => {
    const ledger = new InFlightPatches();
    ledger.add(1, { myVote: 1 });

    ledger.clear();

    expect(ledger.onTheWire(1, 'myVote')).toBe(false);
  });

  it('laysPatchesOverTheMatchingEntriesOnly', () => {
    const ledger = new InFlightPatches();
    ledger.add(1, { myVote: 1, score: 11 });
    const rows = [
      anEntry({ id: 1, score: 10, myVote: 0 }),
      anEntry({ id: 2, score: 4, myVote: 0 }),
    ];

    const [first, second] = ledger.overlayAll(rows);

    expect(first).toMatchObject({ myVote: 1, score: 11 });
    expect(second).toBe(rows[1]);
  });

  it('handsTheListBackWhenNothingIsOnTheWire', () => {
    const rows = [anEntry({ id: 1 })];

    expect(new InFlightPatches().overlayAll(rows)).toBe(rows);
  });

  it('readsBackWhatAPatchChanges', () => {
    const entry = anEntry({ score: 10, myVote: 0 });

    expect(valuesBefore(entry, { myVote: 1, score: 11 })).toEqual({ myVote: 0, score: 10 });
  });
});
