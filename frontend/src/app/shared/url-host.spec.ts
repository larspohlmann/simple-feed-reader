import { hostOf, parseUrl, stripWww } from './url-host';

it('parses a URL around stray whitespace', () => {
  expect(parseUrl(' https://example.com/a ')?.pathname).toBe('/a');
});

it('reads no URL from text that is none', () => {
  expect(parseUrl('not a url')).toBeNull();
});

it('names a host without its www.', () => {
  expect(hostOf(new URL('https://www.example.com/a'))).toBe('example.com');
  expect(hostOf(new URL('https://news.example.com/a'))).toBe('news.example.com');
});

it('strips only a leading www. from a bare host', () => {
  expect(stripWww('www.example.com')).toBe('example.com');
  expect(stripWww('wwwexample.com')).toBe('wwwexample.com');
  expect(stripWww('news.www.example.com')).toBe('news.www.example.com');
});
