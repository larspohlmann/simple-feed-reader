import { SocialNetworkName } from '../../../core/social-network';
import { PostNetwork } from './post-networks.service';
import { ConfiguredTarget, SHARE_TARGETS, ShareTarget, carriesText } from './share-targets';

/** What the menu shares: the page, the page with a hint that a selection could be quoted, or a selected quote. */
export type ShareMenuMode = 'link' | 'hinted' | 'quote';

/** One entry of the menu and of the sheet, under the id the sheet answers with. */
export type ShareItem =
  | {
      readonly kind: 'post';
      readonly id: string;
      readonly network: SocialNetworkName;
      readonly hint: MenuText;
      readonly labelKey: string;
    }
  | { readonly kind: 'hint'; readonly id: string }
  | { readonly kind: 'divider'; readonly id: string }
  | {
      readonly kind: 'target';
      readonly id: string;
      readonly target: ConfiguredTarget;
      readonly linkOnly: boolean;
    };

export interface MenuText {
  readonly key: string;
  readonly params?: Record<string, string>;
}

export type TargetItem = Extract<ShareItem, { kind: 'target' }>;

const QUOTE_LABELS: Partial<Record<ConfiguredTarget['kind'], string>> = {
  system: 'reader.share.quoteSystem',
  copy: 'reader.share.quoteCopy',
};

export function shareMenuItems(networks: readonly PostNetwork[], mode: ShareMenuMode): ShareItem[] {
  const posts = networks.map((network) => postItem(network, mode));
  const hint: ShareItem[] =
    mode === 'hinted' && posts.length > 0 ? [{ kind: 'hint', id: 'quote-hint' }] : [];
  const divider: ShareItem[] = posts.length === 0 ? [] : [{ kind: 'divider', id: 'post-divider' }];
  const targets = SHARE_TARGETS.map((target, index) => configuredItem(target, index, mode));
  return [...posts, ...hint, ...divider, ...targets];
}

function postItem({ network, handles }: PostNetwork, mode: ShareMenuMode): ShareItem {
  const labelKey = mode === 'quote' ? `reader.quote.${network}` : `reader.link.${network}`;
  return { kind: 'post', id: `post-${network}`, network, hint: postHint(handles), labelKey };
}

function postHint(handles: readonly string[]): MenuText {
  return handles.length === 1
    ? { key: 'reader.share.postAs', params: { handle: handles[0] } }
    : { key: 'reader.share.pickAccount' };
}

function configuredItem(target: ShareTarget, index: number, mode: ShareMenuMode): ShareItem {
  const id = String(index);
  if (target.kind === 'divider') return { kind: 'divider', id };
  if (mode !== 'quote') return { kind: 'target', id, target, linkOnly: false };
  const labelKey = QUOTE_LABELS[target.kind] ?? target.labelKey;
  return { kind: 'target', id, target: { ...target, labelKey }, linkOnly: !carriesText(target) };
}
