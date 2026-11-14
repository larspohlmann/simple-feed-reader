import { AccountNetworkName } from '../../../core/social-network';
import { ComposerKind, ComposerSubjectDto, PublishedPostDto } from '../../entry-social';

/** What the composer published, with what the panel and the sent overlay need to show it before the network does. */
export interface SentPost extends PublishedPostDto {
  kind: ComposerKind;
  network: AccountNetworkName;
  text: string;
  authorHandle: string | null;
  target: ComposerSubjectDto;
}
