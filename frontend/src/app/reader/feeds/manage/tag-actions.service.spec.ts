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
import { TagDrop } from '../../tag-drop';
import { TagDto } from '../../models';

const tag: TagDto = { id: 3, name: 'Tech', color: null, icon: null, position: 0, parentId: null };

describe('TagActions', () => {
  let service: TagActions;
  let ctrl: HttpTestingController;
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
    ctrl = TestBed.inject(HttpTestingController);
    open.mockClear();
  });
  afterEach(() => ctrl.verify());

  it('deleteTag: on confirm, DELETEs then reloads tags + subs', () => {
    closed = true;
    const tagSpy = jest
      .spyOn(TestBed.inject(TagsStore), 'load')
      .mockImplementation(() => undefined);
    const subSpy = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
      .mockImplementation(() => undefined);
    service.deleteTag(tag);
    ctrl.expectOne('https://api.test/api/tags/3').flush(null);
    expect(tagSpy).toHaveBeenCalled();
    expect(subSpy).toHaveBeenCalled();
  });

  it('reorderTags: PATCHes /api/tags/reorder then reloads tags', () => {
    const spy = jest.spyOn(TestBed.inject(TagsStore), 'load').mockImplementation(() => undefined);
    service.reorderTags([3, 1, 2]);
    const testRequest = ctrl.expectOne('https://api.test/api/tags/reorder');
    expect(testRequest.request.method).toBe('PATCH');
    expect(testRequest.request.body).toEqual({ tagIds: [3, 1, 2] });
    testRequest.flush({ tags: [] });
    expect(spy).toHaveBeenCalled();
  });

  it('reorderTags: repositions only the listed siblings and keeps every other tag', () => {
    jest.spyOn(TestBed.inject(TagsStore), 'load').mockImplementation(() => undefined);
    const tags = TestBed.inject(TagsStore);
    const reading: TagDto = {
      id: 1,
      name: 'Reading',
      color: null,
      icon: null,
      position: 0,
      parentId: null,
    };
    const longReads: TagDto = {
      id: 2,
      name: 'Long reads',
      color: null,
      icon: null,
      position: 0,
      parentId: 1,
    };
    const tech: TagDto = {
      id: 4,
      name: 'Tech',
      color: null,
      icon: null,
      position: 1,
      parentId: null,
    };
    tags.tags.set([reading, longReads, tech]);

    service.reorderTags([4, 1]);

    expect(tags.tags().map((tag) => [tag.id, tag.position])).toEqual([
      [2, 0],
      [4, 0],
      [1, 1],
    ]);
    ctrl.expectOne('https://api.test/api/tags/reorder').flush({ tags: [] });
  });

  describe('moveTag', () => {
    const tagA: TagDto = { id: 1, name: 'A', color: null, icon: null, position: 0, parentId: null };
    const tagB: TagDto = {
      id: 2,
      name: 'B',
      color: '#fff',
      icon: 'x',
      position: 1,
      parentId: null,
    };
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
      const reorder = ctrl.expectOne('https://api.test/api/tags/reorder');
      expect(reorder.request.body).toEqual({ tagIds: [2, 1] });
      reorder.flush({ tags: [] });
      ctrl.expectNone('https://api.test/api/tags/2');
    });

    it('re-parents, then writes the new sibling order, then reloads tags and feeds', () => {
      service.moveTag(tagB, dropUnder(1, [2]));

      const reparent = ctrl.expectOne('https://api.test/api/tags/2');
      expect(reparent.request.body).toEqual({ name: 'B', color: '#fff', icon: 'x', parentId: 1 });
      ctrl.expectNone('https://api.test/api/tags/reorder');
      reparent.flush({ tag: tagB });
      const reorder = ctrl.expectOne('https://api.test/api/tags/reorder');
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
      ctrl.expectOne('https://api.test/api/tags/2').flush({ tag: tagB });
      ctrl.expectOne('https://api.test/api/tags/reorder').flush({ tags: [] });
    });

    it('skips the reorder and still reloads when the re-parent is refused', () => {
      service.moveTag(tagB, dropUnder(1, [2]));

      ctrl
        .expectOne('https://api.test/api/tags/2')
        .flush(null, { status: 422, statusText: 'Unprocessable' });
      ctrl.expectNone('https://api.test/api/tags/reorder');
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
