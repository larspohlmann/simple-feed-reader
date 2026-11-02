import { SocialNetworkName } from '../../core/social-network';

export const sameMembers = (
  left: ReadonlySet<SocialNetworkName>,
  right: ReadonlySet<SocialNetworkName>,
): boolean => left.size === right.size && [...left].every((network) => right.has(network));
