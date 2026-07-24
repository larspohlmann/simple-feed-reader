import { TestBed } from '@angular/core/testing';
import { TagsSectionComponent } from './tags-section.component';
import { TagsStore } from '../reader/tags.store';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { ManageActions } from '../reader/manage/manage-actions.service';
import { TagDto, SubscriptionDto } from '../reader/models';

const tag = (id: number, name: string): TagDto => ({
  id,
  name,
  color: '#3f8676',
  icon: 'label',
  position: 0,
});

describe('TagsSectionComponent', () => {
  const createTag = jest.fn();
  const editTag = jest.fn();
  const deleteTag = jest.fn();

  function mount(tags: TagDto[], subs: SubscriptionDto[] = []) {
    TestBed.configureTestingModule({
      providers: [
        {
          provide: TagsStore,
          useValue: { tags: () => tags, loading: () => false, error: () => null },
        },
        { provide: SubscriptionsStore, useValue: { subscriptions: () => subs } },
        { provide: ManageActions, useValue: { createTag, editTag, deleteTag } },
      ],
    });
    const f = TestBed.createComponent(TagsSectionComponent);
    f.detectChanges();
    return f;
  }

  beforeEach(() => {
    createTag.mockReset();
    editTag.mockReset();
    deleteTag.mockReset();
  });

  it('lists tags and a feed-usage count', () => {
    const subs: SubscriptionDto[] = [
      {
        id: 1,
        title: 'A',
        customTitle: null,
        feedUrl: 'u',
        siteUrl: null,
        status: 'active',
        sourceFormat: 'xml',
        createdAt: 'x',
        position: 0,
        tags: [tag(1, 'Tech')],
        unreadCount: 0,
      },
    ];
    const el: HTMLElement = mount([tag(1, 'Tech'), tag(2, 'News')], subs).nativeElement;
    expect(el.textContent).toContain('Tech');
    expect(el.textContent).toContain('News');
    expect(el.textContent).toContain('1 feed');
  });

  it('empty state when no tags', () => {
    expect((mount([]).nativeElement as HTMLElement).textContent).toContain('No tags yet');
  });

  it('wires New / Edit / Delete to ManageActions', () => {
    const f = mount([tag(1, 'Tech')]);
    const el = f.nativeElement as HTMLElement;
    (el.querySelector('.new') as HTMLButtonElement).click();
    const rowButtons = el.querySelectorAll('.tag .acts button');
    (rowButtons[0] as HTMLButtonElement).click(); // edit
    (rowButtons[1] as HTMLButtonElement).click(); // delete
    expect(createTag).toHaveBeenCalled();
    expect(editTag).toHaveBeenCalledWith(tag(1, 'Tech'));
    expect(deleteTag).toHaveBeenCalledWith(tag(1, 'Tech'));
  });
});
