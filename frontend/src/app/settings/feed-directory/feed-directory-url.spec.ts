import cases from './feed-directory-url-cases.json';
import { feedDirectoryHost, feedDirectoryUrl } from './feed-directory-url';

describe('feedDirectoryUrl', () => {
  it.each(cases)('normalises $url like the backend', ({ url, normalised, host }) => {
    expect(feedDirectoryUrl(url)).toBe(normalised);
    expect(feedDirectoryHost(url)).toBe(host);
  });
});
