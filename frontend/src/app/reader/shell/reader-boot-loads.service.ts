import { Injectable, inject } from '@angular/core';
import { VersionService } from '../../core/version.service';
import { LabelsStore } from '../labels/labels.store';
import { FollowedStoriesStore } from '../state/followed-stories.store';
import { NetworkSurfaceSwitches } from '../state/network-surface-switches.service';
import { RecommendationsService } from '../state/recommendations.service';
import { SavedSearchesStore } from '../state/saved-searches.store';
import { TagsStore } from '../state/tags.store';

/** What the reader fetches once as it opens; the counts poll keeps it moving after that. */
@Injectable()
export class ReaderBootLoads {
  private readonly savedSearches = inject(SavedSearchesStore);
  private readonly followedStories = inject(FollowedStoriesStore);
  private readonly labels = inject(LabelsStore);
  private readonly networkSwitches = inject(NetworkSurfaceSwitches);
  private readonly tags = inject(TagsStore);
  private readonly recommendations = inject(RecommendationsService);
  private readonly versions = inject(VersionService);

  start(): void {
    this.savedSearches.load();
    this.followedStories.load();
    this.labels.reload();
    this.networkSwitches.loadExclusions();
    this.tags.load();
    this.recommendations.resume();
    this.versions.load();
  }
}
