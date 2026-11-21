import { computed } from '@angular/core';
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

  it('tellsAComputedWhenAFieldGoesOnAndOffTheWire', () => {
    const ledger = new InFlightPatches();
    const pending = computed(() => ledger.onTheWire(1, 'social'));
    expect(pending()).toBe(false);

    const release = ledger.add(1, { social: null });
    expect(pending()).toBe(true);

    release();
    expect(pending()).toBe(false);

    ledger.add(1, { social: null });
    ledger.clear();
    expect(pending()).toBe(false);
  });

  it('findsThePatchOnTheWireWithWhatItReplaced', () => {
    const ledger = new InFlightPatches();
    const found = computed(() => ledger.findOnTheWire(1, 'myVote'));

    const release = ledger.add(1, { myVote: 0 }, { myVote: 1 });

    expect(found()).toMatchObject({ patch: { myVote: 0 }, before: { myVote: 1 } });
    expect(ledger.findOnTheWire(1, 'social')).toBeNull();
    expect(ledger.findOnTheWire(2, 'myVote')).toBeNull();
    release();
    expect(found()).toBeNull();
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
    const other = anEntry({ id: 2, score: 4, myVote: 0 });

    expect(ledger.overlay(anEntry({ id: 1, score: 10, myVote: 0 }))).toMatchObject({
      myVote: 1,
      score: 11,
    });
    expect(ledger.overlay(other)).toBe(other);
  });

  it('handsTheEntryBackWhenNothingIsOnTheWire', () => {
    const entry = anEntry({ id: 1 });

    expect(new InFlightPatches().overlay(entry)).toBe(entry);
  });

  it('readsBackWhatAPatchChanges', () => {
    const entry = anEntry({ score: 10, myVote: 0 });

    expect(valuesBefore(entry, { myVote: 1, score: 11 })).toEqual({ myVote: 0, score: 10 });
  });
});
