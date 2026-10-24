import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../../core/api';
import { TagDto } from '../../models';
import { TagsStore } from '../../state/tags.store';
import { TagCreator } from './tag-creator.service';

describe('TagCreator', () => {
  let ctrl: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    ctrl = TestBed.inject(HttpTestingController);
  });
  afterEach(() => ctrl.verify());

  it('posts a top-level tag and adds it to the store', () => {
    const science: TagDto = {
      id: 9,
      name: 'Science',
      color: null,
      icon: null,
      position: 3,
      parentId: null,
    };
    const seen: TagDto[] = [];

    TestBed.inject(TagCreator)
      .create('Science')
      .subscribe((tag) => seen.push(tag));
    const request = ctrl.expectOne('https://api.test/api/tags');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      name: 'Science',
      color: null,
      icon: null,
      parentId: null,
    });
    request.flush({ tag: science });

    expect(seen).toEqual([science]);
    expect(TestBed.inject(TagsStore).tags()).toEqual([science]);
  });
});
