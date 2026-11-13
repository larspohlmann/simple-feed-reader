import { NetworkFeedKind, SocialNetworkName } from '../../core/social-network';
import { SubscriptionDto, TagDto } from '../../reader/models';
import {
  NETWORK_FEED_KINDS_IN_ORDER,
  SocialSection,
  networkOf,
} from '../../reader/state/social-sections';
import { TagNode, untaggedSubscriptions } from '../../reader/state/subscription-derivations';

export type VisibilityTarget =
  | { readonly kind: 'tag'; readonly tag: TagDto }
  | { readonly kind: 'network'; readonly network: SocialNetworkName }
  | { readonly kind: 'feedKind'; readonly feedKind: NetworkFeedKind }
  | { readonly kind: 'untagged' }
  | { readonly kind: 'feed'; readonly subscription: SubscriptionDto };

/** One row of the matrix, flattened out of the tree in display order. */
export interface VisibilityLine {
  /** Unique per position: a feed under two tags has two lines. */
  readonly key: string;
  readonly target: VisibilityTarget;
  readonly depth: number;
  /** Collapsing any of these hides the line. */
  readonly ancestorKeys: readonly string[];
  readonly hasChildren: boolean;
}

/** What the tree is drawn from, as the sidebar already derives it. */
export interface VisibilitySources {
  readonly tagTree: readonly TagNode[];
  readonly socialSections: readonly SocialSection[];
  readonly subscriptions: readonly SubscriptionDto[];
}

interface VisibilityNode {
  readonly key: string;
  readonly target: VisibilityTarget;
  readonly children: readonly VisibilityNode[];
}

/** Tags with their subtags and feeds, then each network as the sidebar shows it, then the feeds in neither. */
export function visibilityLines(
  { tagTree, socialSections, subscriptions }: VisibilitySources,
  feedFilter: ((subscription: SubscriptionDto) => boolean) | null,
): VisibilityLine[] {
  const nodes = [
    ...tagTree.map(tagNode),
    ...socialSections.map(networkNode).filter((node) => node.children.length > 0),
    ...untaggedNodes(subscriptions),
  ];
  const kept = feedFilter === null ? nodes : pruned(nodes, feedFilter);
  return flattened(kept, []);
}

function tagNode(node: TagNode): VisibilityNode {
  const key = `tag:${node.tag.id}`;
  return {
    key,
    target: { kind: 'tag', tag: node.tag },
    children: [
      ...node.children.map(tagNode),
      ...node.subscriptions.map((subscription) => feedNode(key, subscription)),
    ],
  };
}

/** The account feeds, then one head per feed kind that holds any, then the saved feed. */
function networkNode(section: SocialSection): VisibilityNode {
  const key = `network:${section.network}`;
  const accountFeeds = section.accounts.flatMap((account) => account.subscriptions);
  const kinds = NETWORK_FEED_KINDS_IN_ORDER.filter(
    (feedKind) => section.groups[feedKind].subscriptions.length > 0,
  ).map((feedKind) => feedKindNode(key, feedKind, section.groups[feedKind].subscriptions));
  return {
    key,
    target: { kind: 'network', network: section.network },
    children: [
      ...accountFeeds.map((subscription) => feedNode(key, subscription)),
      ...kinds,
      ...(section.saved === null ? [] : [feedNode(key, section.saved)]),
    ],
  };
}

function feedKindNode(
  networkKey: string,
  feedKind: NetworkFeedKind,
  subscriptions: readonly SubscriptionDto[],
): VisibilityNode {
  const key = `${networkKey}/kind:${feedKind}`;
  return {
    key,
    target: { kind: 'feedKind', feedKind },
    children: subscriptions.map((subscription) => feedNode(key, subscription)),
  };
}

function untaggedNodes(subscriptions: readonly SubscriptionDto[]): VisibilityNode[] {
  const feeds = untaggedSubscriptions(
    subscriptions.filter((subscription) => networkOf(subscription) === null),
  );
  if (feeds.length === 0) return [];
  return [
    {
      key: 'untagged',
      target: { kind: 'untagged' },
      children: feeds.map((subscription) => feedNode('untagged', subscription)),
    },
  ];
}

function feedNode(parentKey: string, subscription: SubscriptionDto): VisibilityNode {
  return {
    key: `${parentKey}/feed:${subscription.id}`,
    target: { kind: 'feed', subscription },
    children: [],
  };
}

/** Matching feeds, and every branch that still leads to one. */
function pruned(
  nodes: readonly VisibilityNode[],
  feedMatches: (subscription: SubscriptionDto) => boolean,
): VisibilityNode[] {
  return nodes.flatMap((node) => {
    if (node.target.kind === 'feed') return feedMatches(node.target.subscription) ? [node] : [];
    const children = pruned(node.children, feedMatches);
    return children.length > 0 ? [{ ...node, children }] : [];
  });
}

function flattened(
  nodes: readonly VisibilityNode[],
  ancestorKeys: readonly string[],
): VisibilityLine[] {
  return nodes.flatMap((node) => [
    {
      key: node.key,
      target: node.target,
      depth: ancestorKeys.length,
      ancestorKeys,
      hasChildren: node.children.length > 0,
    },
    ...flattened(node.children, [...ancestorKeys, node.key]),
  ]);
}
