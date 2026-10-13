import { SocialNetworkName } from '../core/social-network';

export interface EntrySocialDto {
  network: SocialNetworkName;
  isLiked: boolean;
}

/** The answer of `PUT`/`DELETE /api/entries/{id}/like`. */
export interface EntryLikeDto {
  isLiked: boolean;
}
