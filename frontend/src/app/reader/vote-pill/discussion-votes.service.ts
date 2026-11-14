import { Injectable, inject } from '@angular/core';
import { ToastService } from '../../shared/toast/toast.service';
import { SocialPostApi } from '../social-post-api';
import { EntriesStore } from '../state/entries.store';
import { EntryDto } from '../models';
import { VoteValue } from './vote';

/** Votes on a Lemmy post, and asks the network which vote the account holds. */
@Injectable({ providedIn: 'root' })
export class DiscussionVotes {
  private readonly entries = inject(EntriesStore);
  private readonly socialPosts = inject(SocialPostApi);
  private readonly toast = inject(ToastService);

  vote(entry: EntryDto, to: VoteValue): void {
    this.entries.setVote(entry, to, (problem) => this.toast.showProblem(problem));
  }

  refresh(entry: EntryDto): void {
    const from = entry.myVote;
    if (from === null) return;
    this.socialPosts.myVote(entry.id).subscribe({
      next: (answer) => this.entries.adoptVote(entry.id, { from, to: answer.myVote }),
      error: () => undefined,
    });
  }
}
