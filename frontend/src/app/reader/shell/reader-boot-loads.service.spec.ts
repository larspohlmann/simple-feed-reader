import { TestBed } from '@angular/core/testing';
import { VersionService } from '../../core/version.service';
import { LabelsStore } from '../labels/labels.store';
import { FollowedStoriesStore } from '../state/followed-stories.store';
import { NetworkSurfaceSwitches } from '../state/network-surface-switches.service';
import { RecommendationsService } from '../state/recommendations.service';
import { SavedSearchesStore } from '../state/saved-searches.store';
import { TagsStore } from '../state/tags.store';
import { ReaderBootLoads } from './reader-boot-loads.service';

describe('ReaderBootLoads', () => {
  it('fetches each of the reader’s stores once', () => {
    const loads = {
      savedSearches: { load: jest.fn() },
      followedStories: { load: jest.fn() },
      labels: { reload: jest.fn() },
      networkSwitches: { loadExclusions: jest.fn() },
      tags: { load: jest.fn() },
      recommendations: { resume: jest.fn() },
      versions: { load: jest.fn() },
    };
    TestBed.configureTestingModule({
      providers: [
        ReaderBootLoads,
        { provide: SavedSearchesStore, useValue: loads.savedSearches },
        { provide: FollowedStoriesStore, useValue: loads.followedStories },
        { provide: LabelsStore, useValue: loads.labels },
        { provide: NetworkSurfaceSwitches, useValue: loads.networkSwitches },
        { provide: TagsStore, useValue: loads.tags },
        { provide: RecommendationsService, useValue: loads.recommendations },
        { provide: VersionService, useValue: loads.versions },
      ],
    });

    TestBed.inject(ReaderBootLoads).start();

    expect(loads.savedSearches.load).toHaveBeenCalledTimes(1);
    expect(loads.followedStories.load).toHaveBeenCalledTimes(1);
    expect(loads.labels.reload).toHaveBeenCalledTimes(1);
    expect(loads.networkSwitches.loadExclusions).toHaveBeenCalledTimes(1);
    expect(loads.tags.load).toHaveBeenCalledTimes(1);
    expect(loads.recommendations.resume).toHaveBeenCalledTimes(1);
    expect(loads.versions.load).toHaveBeenCalledTimes(1);
  });
});
