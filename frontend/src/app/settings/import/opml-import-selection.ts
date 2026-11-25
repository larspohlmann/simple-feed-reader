import { Injectable, computed, signal } from '@angular/core';
import { toggled } from '../../core/toggled-set';
import { hostOf, parseUrl } from '../../shared/url-host';
import {
  OpmlImportRequest,
  OpmlImportRow,
  OpmlTagChoice,
  OpmlTagMode,
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

function flatten(folder: OpmlPreviewFolder, depth: number, ancestors: string[]): Flattened {
  const path = ancestors.length === 0 ? 'f' : `${ancestors[ancestors.length - 1]}.`;
  const feedRows = folder.feeds.map((feed: OpmlPreviewFeed): OpmlImportRow => ({
    kind: 'feed',
    key: feed.url,
    depth,
    ancestors,
    feed,
    host: hostLabel(feed.url),
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

  isCollapsed(folderKey: string): boolean {
    return this.collapsedKeys().has(folderKey);
  }

  toggleCollapsed(folderKey: string): void {
    this.collapsedKeys.update((previous) => toggled(previous, folderKey));
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

  toggleTag(url: string, tagId: number): void {
    const mode = this.modeOf(url);
    if (mode === 'folder') return;
    const tagIds = toggled(this.chosenTags(url), tagId);
    this.tagChoices.update((previous) => new Map(previous).set(url, { mode, tagIds }));
  }

  applyToTicked(mode: OpmlTagMode, tagIds: ReadonlySet<number>): void {
    this.tagChoices.update((previous) => {
      const next = new Map(previous);
      for (const url of this.selectedUrls()) {
        if (mode === 'folder') next.delete(url);
        else next.set(url, { mode, tagIds });
      }
      return next;
    });
  }

  toRequest(opml: string, parentTagName: string | null): OpmlImportRequest {
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
      parentTagName,
      feedUrls,
      tagIdsByFeedUrl,
      addedTagIdsByFeedUrl,
      folderNames: {},
    };
  }
}
