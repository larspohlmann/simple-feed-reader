import { Subject, throwError } from 'rxjs';
import { PendingAction } from './pending-action';

describe('PendingAction', () => {
  it('holdsTheTagUntilTheActionSettles', () => {
    const action = new PendingAction<'up' | 'down'>();
    const answer = new Subject<void>();

    action.run('up', () => answer);

    expect(action.pending()).toBe('up');
    answer.complete();
    expect(action.pending()).toBeNull();
  });

  it('runsNothingWhileAnEarlierRunIsPending', () => {
    const action = new PendingAction<true>();
    const second = jest.fn(() => new Subject<void>());

    action.run(true, () => new Subject<void>());
    action.run(true, second);

    expect(second).not.toHaveBeenCalled();
  });

  it('letsGoOnFailure', () => {
    const action = new PendingAction<true>();

    action.run(true, () => throwError(() => new Error('no')));

    expect(action.pending()).toBeNull();
  });
});
