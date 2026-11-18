import { Injectable } from '@angular/core';
import { DraftSettingsService } from '../../shared/settings/draft-settings.service';
import { ConnectionChoice } from '../connection-choice';

/** Mirrors `StorySettingsJson`; `writingConnection` is the one that writes, null when none can. */
export interface StoriesSettingsState {
  readonly titlesEnabled: boolean;
  readonly writingConnectionId: number | null;
  readonly writingConnection: ConnectionChoice | null;
  readonly writingCandidates: readonly ConnectionChoice[];
}

export interface SaveStoriesSettings {
  readonly writingConnectionId: number | null;
  readonly titlesEnabled: boolean;
}

/** The story settings' state and writes: both controls save at once, so there is no draft. */
@Injectable()
export class StoriesSettingsService extends DraftSettingsService<
  StoriesSettingsState,
  SaveStoriesSettings,
  object
> {
  protected readonly endpoint = `${this.base}/api/me/ai/stories`;

  protected bodyFromState(state: StoriesSettingsState): SaveStoriesSettings {
    return { writingConnectionId: state.writingConnectionId, titlesEnabled: state.titlesEnabled };
  }
}
