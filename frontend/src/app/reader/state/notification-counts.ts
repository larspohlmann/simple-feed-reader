import { SOCIAL_NETWORKS, SocialNetworkName } from '../../core/social-network';
import { NotificationCountDto } from '../models';

export type NotificationCounts = ReadonlyMap<SocialNetworkName, number>;

export const NO_NOTIFICATION_COUNTS: NotificationCounts = new Map();

export function notificationCountsFrom(dtos: readonly NotificationCountDto[]): NotificationCounts {
  return new Map(dtos.map((dto) => [dto.network, dto.unseenCount]));
}

export function sameNotificationCounts(
  left: NotificationCounts,
  right: NotificationCounts,
): boolean {
  return (
    left.size === right.size &&
    [...left].every(([network, unseenCount]) => right.get(network) === unseenCount)
  );
}

export function unseenNetworks(counts: NotificationCounts): readonly NotificationCountDto[] {
  return SOCIAL_NETWORKS.map((network) => ({
    network,
    unseenCount: counts.get(network) ?? 0,
  })).filter((unseen) => unseen.unseenCount > 0);
}

export function totalUnseen(counts: NotificationCounts): number {
  return [...counts.values()].reduce((total, unseenCount) => total + unseenCount, 0);
}
