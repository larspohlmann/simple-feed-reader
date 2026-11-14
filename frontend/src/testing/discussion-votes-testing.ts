import { Provider } from '@angular/core';
import { DiscussionVotes } from '../app/reader/vote-pill/discussion-votes.service';

export function provideDiscussionVotesStub(): Provider {
  return { provide: DiscussionVotes, useValue: { vote: jest.fn(), refresh: jest.fn() } };
}
