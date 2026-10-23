import { SocialNetworkName } from '../core/social-network';
import { SavedSearchWire } from './models';

export interface SavedSearchesResponse {
  savedSearches: SavedSearchWire[];
  includedNetworks: SocialNetworkName[];
}

export interface SavedSearchDraft {
  term: string;
  wholeWord: boolean;
  phrase: boolean;
}
