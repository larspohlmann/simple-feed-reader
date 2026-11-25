import { Injectable, computed, signal } from '@angular/core';
import { toggled } from '../../core/toggled-set';
import { hostOf, parseHttpUrl, parseUrl } from '../../shared/url-host';
import {
  OpmlImportRequest,
  OpmlImportRow,
  OpmlTagChoice,
  OpmlTagMode,
  OpmlPlacement,
  OpmlPreview,
  OpmlPreviewFeed,
  OpmlPreviewFolder,
} from './opml-import.models';

type FolderRow = Extract<OpmlImportRow, { kind: 'folder' }>;

interface Flattened {
  rows: OpmlImportRow[];
  importableUrls: string[];
}

function hostLabel(url: string): string {
  const parsed = parseUrl(url);
  return parsed === null ? url : hostOf(parsed);
}

function siteHrefOf(feed: OpmlPreviewFeed): string | null {
  if (feed.siteUrl !== null) return feed.siteUrl;
  const origin = parseHttpUrl(feed.url)?.origin;
  return origin === undefined ? null : `${origin}/`;
}

function flatten(folder: OpmlPreviewFolder, depth: number, ancestors: string[]): Flattened {
  const path = ancestors.length === 0 ? '' : `${ancestors[ancestors.length - 1]}.`;
  const feedRows = folder.feeds.map((feed: OpmlPreviewFeed): OpmlImportRow => ({
    kind: 'feed',
    key: feed.url,
    depth,
    ancestors,
    feed,
    host: hostLabel(feed.url),
    siteHref: siteHrefOf(feed),
    destination: folder.destination,
  }));
  const importableUrls = folder.feeds
    .filter((feed) => feed.state === 'new')
    .map((feed) => feed.url);
  const folderRows: OpmlImportRow[] = [];
  folder.folders.forEach((child, index) => {
    const key = `${path}${index}`;
    const below = flatten(child, depth + 1, [...ancestors, key]);
    folderRows.push(
      {
        kind: 'folder',
        key,
        depth,
        ancestors,
        name: child.name ?? '',
        tagName: child.dropped
          ? (child.name ?? '')
          : (child.destination.at(-1) ?? child.name ?? ''),
        dropped: child.dropped,
        importableUrls: below.importableUrls,
      },
      ...below.rows,
    );
    importableUrls.push(...below.importableUrls);
  });
  return { rows: [...feedRows, ...folderRows], importableUrls };
}

function collectDroppedNames(folder: OpmlPreviewFolder): string[] {
  return folder.folders.flatMap((child) => [
    ...(child.dropped ? [child.name ?? ''] : []),
    ...collectDroppedNames(child),
  ]);
}

@Injectable()
export class OpmlImportSelection {
  private readonly preview = signal<OpmlPreview | null>(null);
  private readonly selectedUrls = signal<ReadonlySet<string>>(new Set());
  private readonly tagChoices = signal<ReadonlyMap<string, OpmlTagChoice>>(new Map());
  private readonly collapsedKeys = signal<ReadonlySet<string>>(new Set());
  private readonly renamedFolders = signal<Readonly<Record<string, string>>>({});

  private readonly flattened = computed<Flattened>(() => {
    const current = this.preview();
    return current === null ? { rows: [], importableUrls: [] } : flatten(current.root, 0, []);
  });
  private readonly importable = computed<ReadonlySet<string>>(
    () => new Set(this.flattened().importableUrls),
  );
  readonly folderStates = computed(() => {
    const states = new Map<string, 'all' | 'some' | 'none'>();
    const selected = this.selectedUrls();
    for (const row of this.flattened().rows) {
      if (row.kind !== 'folder') continue;
      const chosen = row.importableUrls.filter((url) => selected.has(url)).length;
      if (chosen === 0) states.set(row.key, 'none');
      else states.set(row.key, chosen === row.importableUrls.length ? 'all' : 'some');
    }
    return states;
  });

  readonly folderNames = this.renamedFolders.asReadonly();

  readonly rows = computed(() => this.flattened().rows);
  readonly visibleRows = computed(() => {
    const collapsed = this.collapsedKeys();
    return this.rows().filter((row) => !row.ancestors.some((key) => collapsed.has(key)));
  });
  private readonly folderKeys = computed(() =>
    this.rows().flatMap((row) => (row.kind === 'folder' ? [row.key] : [])),
  );
  readonly hasFolders = computed(() => this.folderKeys().length > 0);
  readonly anyExpanded = computed(() =>
    this.folderKeys().some((key) => !this.collapsedKeys().has(key)),
  );
  private readonly destinations = computed(
    () =>
      new Map(
        this.rows().flatMap((row) =>
          row.kind === 'feed' ? [[row.feed.url, row.destination] as const] : [],
        ),
      ),
  );
  readonly tickedDestinations = computed(() => {
    const byKey = new Map<string, string[]>();
    for (const url of this.selectedUrls()) {
      const destination = this.destinations().get(url) ?? [];
      byKey.set(JSON.stringify(destination), destination);
    }
    return [...byKey.values()];
  });
  readonly selected = this.selectedUrls.asReadonly();
  readonly selectedCount = computed(() => this.selectedUrls().size);
  readonly allTicked = computed(
    () => this.selectedCount() > 0 && this.selectedCount() === this.importable().size,
  );
  readonly freeSlots = computed(() => this.preview()?.freeSlots ?? 0);
  readonly overLimit = computed(() => this.selectedCount() > this.freeSlots());
  readonly droppedFolders = computed(() => {
    const current = this.preview();
    return current === null ? [] : collectDroppedNames(current.root);
  });

  load(preview: OpmlPreview): void {
    const isFirstLoad = this.preview() === null;
    this.preview.set(preview);
    const importable = this.importable();
    this.selectedUrls.update((previous) =>
      isFirstLoad ? importable : new Set([...previous].filter((url) => importable.has(url))),
    );
  }

  toggleFeed(url: string): void {
    if (!this.importable().has(url)) return;
    this.selectedUrls.update((previous) => toggled(previous, url));
  }

  toggleFolder(row: FolderRow): void {
    const clears = this.folderState(row) === 'all';
    this.selectedUrls.update((previous) => {
      const next = new Set(previous);
      for (const url of row.importableUrls) {
        if (clears) next.delete(url);
        else next.add(url);
      }
      return next;
    });
  }

  renameFolder(row: FolderRow, name: string): void {
    const trimmed = name.trim();
    this.renamedFolders.update((previous) => {
      const next = { ...previous };
      if (trimmed === '' || trimmed === row.name) delete next[row.key];
      else next[row.key] = trimmed;
      return next;
    });
  }

  resetFolder(row: FolderRow): void {
    this.renameFolder(row, '');
  }

  isRenamed(row: FolderRow): boolean {
    return row.key in this.renamedFolders();
  }

  isCollapsed(folderKey: string): boolean {
    return this.collapsedKeys().has(folderKey);
  }

  toggleCollapsed(folderKey: string): void {
    this.collapsedKeys.update((previous) => toggled(previous, folderKey));
  }

  toggleAll(): void {
    if (this.anyExpanded()) this.collapseAll();
    else this.expandAll();
  }

  collapseAll(): void {
    this.collapsedKeys.set(new Set(this.folderKeys()));
  }

  expandAll(): void {
    this.collapsedKeys.set(new Set());
  }

  folderState(row: FolderRow): 'all' | 'some' | 'none' {
    return this.folderStates().get(row.key) ?? 'none';
  }

  selectAll(): void {
    this.selectedUrls.set(this.importable());
  }

  selectNone(): void {
    this.selectedUrls.set(new Set());
  }

  modeOf(url: string): OpmlTagMode {
    return this.tagChoices().get(url)?.mode ?? 'folder';
  }

  chosenTags(url: string): ReadonlySet<number> {
    return this.tagChoices().get(url)?.tagIds ?? new Set();
  }

  setMode(url: string, mode: OpmlTagMode): void {
    this.tagChoices.update((previous) => {
      const next = new Map(previous);
      if (mode === 'folder') next.delete(url);
      else next.set(url, { mode, tagIds: this.chosenTags(url) });
      return next;
    });
  }

  folderTagName(url: string): string | null {
    return this.destinations().get(url)?.at(-1) ?? null;
  }

  dropFolder(url: string): void {
    this.setMode(url, 'tagsOnly');
  }

  restoreFolder(url: string): void {
    this.setMode(url, this.chosenTags(url).size === 0 ? 'folder' : 'folderPlusTags');
  }

  toggleTag(url: string, tagId: number): void {
    const keepsFolder = this.modeOf(url) !== 'tagsOnly' && this.folderTagName(url) !== null;
    const tagIds = toggled(this.chosenTags(url), tagId);
    this.tagChoices.update((previous) => {
      const next = new Map(previous);
      if (keepsFolder && tagIds.size === 0) next.delete(url);
      else next.set(url, { mode: keepsFolder ? 'folderPlusTags' : 'tagsOnly', tagIds });
      return next;
    });
  }

  applyToTicked(mode: OpmlTagMode, tagIds: ReadonlySet<number>): void {
    this.tagChoices.update((previous) => {
      const next = new Map(previous);
      for (const url of this.selectedUrls()) {
        if (mode === 'folder') next.delete(url);
        else next.set(url, { mode: this.folderTagName(url) === null ? 'tagsOnly' : mode, tagIds });
      }
      return next;
    });
  }

  toRequest(opml: string, placement: OpmlPlacement): OpmlImportRequest {
    const feedUrls = this.rows().flatMap((row) =>
      row.kind === 'feed' && this.selectedUrls().has(row.feed.url) ? [row.feed.url] : [],
    );
    const tagIdsByFeedUrl: Record<string, number[]> = {};
    const addedTagIdsByFeedUrl: Record<string, number[]> = {};
    for (const url of feedUrls) {
      const choice = this.tagChoices().get(url);
      if (choice === undefined) continue;
      const target = choice.mode === 'tagsOnly' ? tagIdsByFeedUrl : addedTagIdsByFeedUrl;
      target[url] = [...choice.tagIds].sort((left, right) => left - right);
    }
    return {
      opml,
      parentTagName: placement.parentTagName,
      feedUrls,
      tagIdsByFeedUrl,
      addedTagIdsByFeedUrl,
      folderNames: placement.folderNames,
    };
  }
}
