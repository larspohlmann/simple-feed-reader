import { likeableSocial } from '../entry-social';
import { EntryDto, EntryStatePatch } from '../models';
import { VoteValue } from '../vote-pill/vote';
import { RowPatch } from './in-flight-patches';

/** The like as it shows at once: on the post's network where the reader can like there, else here. A network
 *  unlike takes the local like with it, as the server does. */
export function buildLikePatch(entry: EntryDto, isLiked: boolean): RowPatch {
  const social = likeableSocial(entry);
  if (!social) return { isLiked };
  const networkLike = { social: { ...social, isLiked } };
  return isLiked ? networkLike : { ...networkLike, isLiked: false };
}

/** The vote as it shows at once; it replaces the entry's local like, as the server does. */
export function buildVotePatch(to: VoteValue): RowPatch {
  return { myVote: to, isLiked: false };
}

/** Mirrors the backend's flag coupling (#482): viewing also hides
 *  (ViewedImpliesHiddenListener), un-hiding clears viewed (EntryState::markUnread).
 *  Un-ticking isViewed alone leaves hidden set — hiding from the unread list is sticky. */
export function localStatePatch(patch: EntryStatePatch): EntryStatePatch {
  if (patch.isViewed === true) return { ...patch, isHidden: true };
  if (patch.isHidden === false) return { ...patch, isViewed: false };
  return patch;
}
