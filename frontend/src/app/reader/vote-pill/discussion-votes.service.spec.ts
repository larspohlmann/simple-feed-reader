import { TestBed } from '@angular/core/testing';
import { of, throwError } from 'rxjs';
import { Problem } from '../../core/problem';
import { anEntry } from '../../../testing/entry-testing';
import { ToastService } from '../../shared/toast/toast.service';
import { SocialPostApi } from '../social-post-api';
import { EntriesStore } from '../state/entries.store';
import { DiscussionVotes } from './discussion-votes.service';

describe('DiscussionVotes', () => {
  const entries = { setVote: jest.fn(), adoptVote: jest.fn() };
  const socialPosts = { myVote: jest.fn() };
  const toast = { showProblem: jest.fn() };
  let votes: DiscussionVotes;

  beforeEach(() => {
    [entries.setVote, entries.adoptVote, socialPosts.myVote, toast.showProblem].forEach((spy) =>
      spy.mockReset(),
    );
    TestBed.configureTestingModule({
      providers: [
        { provide: EntriesStore, useValue: entries },
        { provide: SocialPostApi, useValue: socialPosts },
        { provide: ToastService, useValue: toast },
      ],
    });
    votes = TestBed.inject(DiscussionVotes);
  });

  it('votesThroughTheStoreAndToastsAFailure', () => {
    const entry = anEntry({ score: 3, myVote: 0 });
    entries.setVote.mockImplementation(
      (_entry: unknown, _to: unknown, onError: (problem: Problem) => void) =>
        onError({ title: 'Nope' } as Problem),
    );

    votes.vote(entry, 1);

    expect(entries.setVote).toHaveBeenCalledWith(entry, 1, expect.any(Function));
    expect(toast.showProblem).toHaveBeenCalledWith({ title: 'Nope' });
  });

  it('adoptsTheVoteTheNetworkHolds', () => {
    socialPosts.myVote.mockReturnValue(of({ myVote: -1 }));
    const entry = anEntry({ id: 7, score: 3, myVote: 0 });

    votes.refresh(entry);

    expect(entries.adoptVote).toHaveBeenCalledWith(7, { from: 0, to: -1 });
  });

  it('asksNothingWithoutAVote', () => {
    votes.refresh(anEntry({ myVote: null }));

    expect(socialPosts.myVote).not.toHaveBeenCalled();
  });

  it('staysSilentWhenTheRefreshFails', () => {
    socialPosts.myVote.mockReturnValue(throwError(() => new Error('down')));

    votes.refresh(anEntry({ score: 3, myVote: 0 }));

    expect(entries.adoptVote).not.toHaveBeenCalled();
    expect(toast.showProblem).not.toHaveBeenCalled();
  });
});
