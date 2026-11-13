import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Dialog } from '@angular/cdk/dialog';
import { of } from 'rxjs';
import { API_BASE_URL } from '../../../core/api';
import { TagActions } from './tag-actions.service';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { TagsStore } from '../../state/tags.store';
import { SavedSearchesStore } from '../../state/saved-searches.store';
import { EntriesStore } from '../../state/entries.store';
import { TagDrop } from '../../tag-drop';
import { TagDto } from '../../models';
import { makeTag } from '../../testing/tag.factory';

const tag: TagDto = makeTag(3, 'Tech', { position: 0 });

describe('TagActions', () => {
  let service: TagActions;
  let http: HttpTestingController;
  let closed: unknown;
  const open = jest.fn(() => ({ closed: of(closed) }));

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: Dialog, useValue: { open } },
      ],
    });
    service = TestBed.inject(TagActions);
    http = TestBed.inject(HttpTestingController);
    open.mockClear();
  });
  afterEach(() => http.verify());

  it('deleteTag: on confirm, DELETEs then reloads tags + subs', () => {
    closed = true;
    const tagSpy = jest
      .spyOn(TestBed.inject(TagsStore), 'load')
      .mockImplementation(() => undefined);
    const subSpy = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
      .mockImplementation(() => undefined);
    service.deleteTag(tag);
    http.expectOne('https://api.test/api/tags/3').flush(null);
    expect(tagSpy).toHaveBeenCalled();
    expect(subSpy).toHaveBeenCalled();
  });

  describe('setTagInclusion', () => {
    const subtag = makeTag(5, 'Long reads', { color: '#123456', icon: 'book', parentId: 3 });

    it('switches the tag at once and PATCHes only the changed flag', () => {
      jest.spyOn(TestBed.inject(TagsStore), 'load').mockImplementation(() => undefined);
      jest
        .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
        .mockImplementation(() => undefined);
      const tags = TestBed.inject(TagsStore);
      tags.tags.set([tag, subtag]);

      service.setTagInclusion(subtag, 'forYou', false);

      expect(tags.tags().find((listed) => listed.id === 5)?.includeInForYou).toBe(false);
      const testRequest = http.expectOne('https://api.test/api/tags/5');
      expect(testRequest.request.method).toBe('PATCH');
      expect(testRequest.request.body).toEqual({
        name: 'Long reads',
        color: '#123456',
        icon: 'book',
        parentId: 3,
        includeInForYou: false,
      });
      testRequest.flush({ tag: { ...subtag, includeInForYou: false } });
    });

    it('resends the live tag, not the snapshot the caller holds', () => {
      jest.spyOn(TestBed.inject(TagsStore), 'load').mockImplementation(() => undefined);
      jest
        .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
        .mockImplementation(() => undefined);
      const renamed = { ...subtag, name: 'Longreads', parentId: null };
      TestBed.inject(TagsStore).tags.set([tag, renamed]);

      service.setTagInclusion(subtag, 'allItems', false);

      const testRequest = http.expectOne('https://api.test/api/tags/5');
      expect(testRequest.request.body).toEqual({
        name: 'Longreads',
        color: '#123456',
        icon: 'book',
        parentId: null,
        includeInAllItems: false,
      });
      testRequest.flush({ tag: renamed });
    });

    it('reloads tags and feeds once the write lands', () => {
      const tagSpy = jest
        .spyOn(TestBed.inject(TagsStore), 'load')
        .mockImplementation(() => undefined);
      const subSpy = jest
        .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
        .mockImplementation(() => undefined);

      service.setTagInclusion(tag, 'allItems', false);
      http.expectOne('https://api.test/api/tags/3').flush({ tag });

      expect(tagSpy).toHaveBeenCalledTimes(1);
      expect(subSpy).toHaveBeenCalledTimes(1);
    });

    it('reloads saved-search badges when the saved-search switch moves', () => {
      jest.spyOn(TestBed.inject(TagsStore), 'load').mockImplementation(() => undefined);
      jest
        .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
        .mockImplementation(() => undefined);
      const savedSearchSpy = jest
        .spyOn(TestBed.inject(SavedSearchesStore), 'load')
        .mockImplementation(() => undefined);

      service.setTagInclusion(tag, 'savedSearches', false);
      http.expectOne('https://api.test/api/tags/3').flush({ tag });

      expect(savedSearchSpy).toHaveBeenCalledTimes(1);
    });

    it('sends the Trending flag and reloads the Trending list when that switch moves', () => {
      jest.spyOn(TestBed.inject(TagsStore), 'load').mockImplementation(() => undefined);
      jest
        .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
        .mockImplementation(() => undefined);
      const reloadTrending = jest
        .spyOn(TestBed.inject(EntriesStore), 'reloadTrending')
        .mockImplementation(() => undefined);

      service.setTagInclusion(tag, 'trending', false);
      const testRequest = http.expectOne('https://api.test/api/tags/3');
      expect(testRequest.request.body).toEqual(
        expect.objectContaining({ includeInTrending: false }),
      );
      testRequest.flush({ tag });

      expect(reloadTrending).toHaveBeenCalledTimes(1);
    });

    it('reloads the tags after a failed write, which snaps the switch back', () => {
      const tagSpy = jest
        .spyOn(TestBed.inject(TagsStore), 'load')
        .mockImplementation(() => undefined);
      jest
        .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
        .mockImplementation(() => undefined);

      service.setTagInclusion(tag, 'allItems', false);
      http
        .expectOne('https://api.test/api/tags/3')
        .flush(null, { status: 500, statusText: 'Server Error' });

      expect(tagSpy).toHaveBeenCalledTimes(1);
    });
  });

  it('reorderTags: PATCHes /api/tags/reorder then reloads tags', () => {
    const spy = jest.spyOn(TestBed.inject(TagsStore), 'load').mockImplementation(() => undefined);
    service.reorderTags([3, 1, 2]);
    const testRequest = http.expectOne('https://api.test/api/tags/reorder');
    expect(testRequest.request.method).toBe('PATCH');
    expect(testRequest.request.body).toEqual({ tagIds: [3, 1, 2] });
    testRequest.flush({ tags: [] });
    expect(spy).toHaveBeenCalled();
  });

  it('reorderTags: repositions only the listed siblings and keeps every other tag', () => {
    jest.spyOn(TestBed.inject(TagsStore), 'load').mockImplementation(() => undefined);
    const tags = TestBed.inject(TagsStore);
    const reading = makeTag(1, 'Reading', { position: 0 });
    const longReads = makeTag(2, 'Long reads', { position: 0, parentId: 1 });
    const tech = makeTag(4, 'Tech', { position: 1 });
    tags.tags.set([reading, longReads, tech]);

    service.reorderTags([4, 1]);

    expect(tags.tags().map((tag) => [tag.id, tag.position])).toEqual([
      [2, 0],
      [4, 0],
      [1, 1],
    ]);
    http.expectOne('https://api.test/api/tags/reorder').flush({ tags: [] });
  });

  describe('moveTag', () => {
    const tagA = makeTag(1, 'A', { position: 0 });
    const tagB = makeTag(2, 'B', { color: '#fff', icon: 'x', position: 1 });
    let tagsStore: TagsStore;
    let tagsLoad: jest.SpyInstance;
    let subscriptionsLoad: jest.SpyInstance;

    const dropUnder = (parentId: number | null, order: number[]): TagDrop => ({
      parentId,
      order,
      mark: { kind: 'before', tagId: order[0] },
    });

    beforeEach(() => {
      tagsStore = TestBed.inject(TagsStore);
      tagsStore.tags.set([tagA, tagB]);
      tagsLoad = jest.spyOn(tagsStore, 'load').mockImplementation(() => undefined);
      subscriptionsLoad = jest
        .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
        .mockImplementation(() => undefined);
    });

    it('only reorders when the parent stays, showing the new positions at once', () => {
      service.moveTag(tagB, dropUnder(null, [2, 1]));

      expect(tagsStore.tags().map(({ id, position }) => [id, position])).toEqual([
        [2, 0],
        [1, 1],
      ]);
      const reorder = http.expectOne('https://api.test/api/tags/reorder');
      expect(reorder.request.body).toEqual({ tagIds: [2, 1] });
      reorder.flush({ tags: [] });
      http.expectNone('https://api.test/api/tags/2');
    });

    it('re-parents, then writes the new sibling order, then reloads tags and feeds', () => {
      service.moveTag(tagB, dropUnder(1, [2]));

      const reparent = http.expectOne('https://api.test/api/tags/2');
      expect(reparent.request.body).toEqual({ name: 'B', color: '#fff', icon: 'x', parentId: 1 });
      http.expectNone('https://api.test/api/tags/reorder');
      reparent.flush({ tag: tagB });
      const reorder = http.expectOne('https://api.test/api/tags/reorder');
      expect(reorder.request.body).toEqual({ tagIds: [2] });
      reorder.flush({ tags: [] });
      expect(tagsLoad).toHaveBeenCalled();
      expect(subscriptionsLoad).toHaveBeenCalled();
    });

    it('shows the new parent at once', () => {
      service.moveTag(tagB, dropUnder(1, [2]));

      expect(tagsStore.tags().find((tag) => tag.id === 2)).toEqual(
        expect.objectContaining({ parentId: 1, position: 0 }),
      );
      http.expectOne('https://api.test/api/tags/2').flush({ tag: tagB });
      http.expectOne('https://api.test/api/tags/reorder').flush({ tags: [] });
    });

    it('skips the reorder and still reloads when the re-parent is refused', () => {
      service.moveTag(tagB, dropUnder(1, [2]));

      http
        .expectOne('https://api.test/api/tags/2')
        .flush(null, { status: 422, statusText: 'Unprocessable' });
      http.expectNone('https://api.test/api/tags/reorder');
      expect(tagsLoad).toHaveBeenCalled();
      expect(subscriptionsLoad).toHaveBeenCalled();
    });
  });

  it('createTag: reloads tags when the dialog returns a tag', () => {
    closed = tag;
    const tagSpy = jest
      .spyOn(TestBed.inject(TagsStore), 'load')
      .mockImplementation(() => undefined);
    service.createTag();
    expect(tagSpy).toHaveBeenCalled();
  });

  it.each([
    ['at the top level by default', undefined, null],
    ['under the given parent', 7, 7],
  ])('createTag: opens the dialog creating %s', (_case, parentId, expectedParentId) => {
    closed = undefined;
    service.createTag(parentId);
    expect(open).toHaveBeenCalledWith(expect.anything(), {
      data: { kind: 'create', parentId: expectedParentId },
      panelClass: 'app-dialog',
    });
  });

  it('editTag: opens the dialog editing the tag', () => {
    closed = undefined;
    service.editTag(tag);
    expect(open).toHaveBeenCalledWith(expect.anything(), {
      data: { kind: 'edit', tag },
      panelClass: 'app-dialog',
    });
  });
});
