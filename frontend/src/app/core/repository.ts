export const REPOSITORY_URL = 'https://github.com/larspohlmann/simple-feed-reader';

/** A pushed tag: a release (`v1.7.0`) or a Strato deploy (`v1.7.1-dev.1`). An
 *  untagged build reports `dev` or a `git describe` string, which name no tag. */
const TAG = /^v\d+\.\d+\.\d+(-dev\.\d+)?$/;

/** The version's page on GitHub, or the release list when it names no tag. */
export function releasePageUrl(version: string): string {
  return TAG.test(version)
    ? `${REPOSITORY_URL}/releases/tag/${version}`
    : `${REPOSITORY_URL}/releases`;
}
