import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { SettingsComponent } from './settings.component';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { TagsStore } from '../reader/tags.store';

describe('SettingsComponent', () => {
  const subLoad = jest.fn();
  const tagLoad = jest.fn();

  function mount() {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        {
          provide: SubscriptionsStore,
          useValue: {
            load: subLoad,
            subscriptions: () => [],
            loading: () => false,
            error: () => null,
          },
        },
        {
          provide: TagsStore,
          useValue: { load: tagLoad, tags: () => [], loading: () => false, error: () => null },
        },
      ],
    }).overrideComponent(SettingsComponent, {
      set: { imports: [], template: '<h1>Settings</h1>' },
    });
    const f = TestBed.createComponent(SettingsComponent);
    f.detectChanges();
    return f;
  }

  beforeEach(() => {
    subLoad.mockReset();
    tagLoad.mockReset();
  });

  it('loads subscriptions and tags on init', () => {
    mount();
    expect(subLoad).toHaveBeenCalled();
    expect(tagLoad).toHaveBeenCalled();
  });
});
