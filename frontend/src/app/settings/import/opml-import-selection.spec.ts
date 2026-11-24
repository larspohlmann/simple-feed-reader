import { TestBed } from '@angular/core/testing';
import { OpmlFeedState, OpmlImportRow, OpmlPreview, OpmlPreviewFeed } from './opml-import.models';
import { OpmlImportSelection } from './opml-import-selection';

function feed(url: string, state: OpmlFeedState = 'new'): OpmlPreviewFeed {
  return { url, title: null, state };
}

function previewOf(
  freeSlots = 10,
  destinationPrefix = 'X',
  b1State: OpmlFeedState = 'new',
): OpmlPreview {
  return {
    freeSlots,
    root: {
      name: null,
      dropped: false,
      destination: [],
      feeds: [feed('r')],
      folders: [
        {
          name: 'A',
          dropped: false,
          destination: [destinationPrefix, 'A'],
          feeds: [feed('a1'), feed('a2', 'subscribed')],
          folders: [
            {
              name: 'B',
              dropped: false,
              destination: [destinationPrefix, 'A', 'B'],
              feeds: [feed('b1', b1State), feed('b2', 'scraped')],
              folders: [],
            },
          ],
        },
        { name: 'Deep', dropped: true, destination: [], feeds: [], folders: [] },
      ],
    },
  };
}

function folderRow(
  selection: OpmlImportSelection,
  name: string,
): OpmlImportRow & { kind: 'folder' } {
  const row = selection
    .rows()
    .find((candidate) => candidate.kind === 'folder' && candidate.name === name);
  if (row === undefined || row.kind !== 'folder') throw new Error(`no folder ${name}`);
  return row;
}

describe('OpmlImportSelection', () => {
  let selection: OpmlImportSelection;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [OpmlImportSelection] });
    selection = TestBed.inject(OpmlImportSelection);
    selection.load(previewOf());
  });

  it('selects every new feed on first load', () => {
    expect([...selection.selected()].sort()).toEqual(['a1', 'b1', 'r']);
    expect(selection.selectedCount()).toBe(3);
  });

  it('flattens the tree in document order', () => {
    expect(selection.rows().map((row) => `${row.kind}:${row.key}:${row.depth}`)).toEqual([
      'feed:r:0',
      'folder:f0:0',
      'feed:a1:1',
      'feed:a2:1',
      'folder:f0.0:1',
      'feed:b1:2',
      'feed:b2:2',
      'folder:f1:0',
    ]);
  });

  it('a folder row knows the importable feeds below it', () => {
    expect(folderRow(selection, 'A').importableUrls).toEqual(['a1', 'b1']);
    expect(folderRow(selection, 'B').importableUrls).toEqual(['b1']);
  });

  it('reports a folder as all, some or none', () => {
    selection.toggleFeed('b1');
    expect(selection.folderState(folderRow(selection, 'A'))).toBe('some');
    expect(selection.folderState(folderRow(selection, 'B'))).toBe('none');
    selection.selectNone();
    expect(selection.folderState(folderRow(selection, 'A'))).toBe('none');
    selection.selectAll();
    expect(selection.folderState(folderRow(selection, 'A'))).toBe('all');
  });

  it('reports a folder without importable feeds as none and selects nothing when toggled', () => {
    const deep = folderRow(selection, 'Deep');
    expect(selection.folderState(deep)).toBe('none');
    selection.toggleFolder(deep);
    expect(selection.selectedCount()).toBe(3);
  });

  it('toggling a full folder clears it and a partial one fills it', () => {
    const folder = folderRow(selection, 'A');
    selection.toggleFolder(folder);
    expect([...selection.selected()]).toEqual(['r']);
    selection.toggleFolder(folder);
    expect([...selection.selected()].sort()).toEqual(['a1', 'b1', 'r']);
    selection.toggleFeed('a1');
    selection.toggleFolder(folder);
    expect(selection.folderState(folder)).toBe('all');
  });

  it('never selects a feed that is not new', () => {
    selection.toggleFeed('a2');
    selection.selectNone();
    selection.selectAll();
    expect(selection.selected().has('a2')).toBe(false);
    expect(selection.selected().has('b2')).toBe(false);
  });

  it('is over the limit when more are selected than slots are free', () => {
    selection.load(previewOf(2));
    expect(selection.freeSlots()).toBe(2);
    expect(selection.overLimit()).toBe(true);
    selection.toggleFeed('r');
    expect(selection.overLimit()).toBe(false);
  });

  it('names the dropped folders', () => {
    expect(selection.droppedFolders()).toEqual(['Deep']);
  });

  it('keeps the selection and the changed tags across a new preview', () => {
    selection.toggleFeed('r');
    selection.changeTags('a1');
    selection.toggleTag('a1', 7);
    selection.load(previewOf(10, 'Y'));
    expect([...selection.selected()].sort()).toEqual(['a1', 'b1']);
    expect([...selection.chosenTags('a1')]).toEqual([7]);
  });

  it('drops a feed the new preview no longer offers', () => {
    selection.load(previewOf(10, 'X', 'subscribed'));
    expect(selection.selected().has('b1')).toBe(false);
  });

  it('keeps row keys identical across a refreshed preview of the same tree', () => {
    const before = selection.rows().map((row) => row.key);
    selection.load(previewOf(10, 'Y'));
    expect(selection.rows().map((row) => row.key)).toEqual(before);
  });

  it('builds the request from the selection', () => {
    selection.changeTags('b1');
    selection.toggleTag('b1', 9);
    selection.toggleTag('b1', 3);
    selection.changeTags('r');
    selection.toggleFeed('r');
    expect(selection.toRequest('<opml/>', ' ')).toEqual({
      opml: '<opml/>',
      parentTagName: null,
      feedUrls: ['a1', 'b1'],
      tagIdsByFeedUrl: { b1: [3, 9] },
    });
  });

  it('sends a named parent tag as given', () => {
    expect(selection.toRequest('<opml/>', 'Imported').parentTagName).toBe('Imported');
  });

  it('use folder forgets the changed tags', () => {
    selection.changeTags('a1');
    selection.useFolder('a1');
    expect(selection.isChanged('a1')).toBe(false);
    expect(selection.toRequest('<opml/>', null).tagIdsByFeedUrl).toEqual({});
  });

  it('an empty choice is sent as untagged', () => {
    selection.changeTags('a1');
    expect(selection.isChanged('a1')).toBe(true);
    expect(selection.toRequest('<opml/>', null).tagIdsByFeedUrl).toEqual({ a1: [] });
  });

  it('toggles a tag off again', () => {
    selection.changeTags('a1');
    selection.toggleTag('a1', 4);
    selection.toggleTag('a1', 4);
    expect([...selection.chosenTags('a1')]).toEqual([]);
  });
});
