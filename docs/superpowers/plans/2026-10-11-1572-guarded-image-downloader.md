# #1572 — Rename FaviconFetcher to GuardedImageDownloader

**Goal:** the Image module's guarded image download (used by `ImageVerifier`, `DigestImageEmbedder`,
`CatalogFaviconWarmer`) carries a name for what it does. Pure rename, no behaviour change.

## Rulings

- `GuardedImageDownloader` / `GuardedImageDownloaderInterface`, folder `Service/Image/GuardedImageDownloader/`.
  "Guarded" keeps it apart from the image proxy's `ImageDownloader`.
- The acceptance asks for no `Favicon` name on the general download path, so the path's model and exceptions
  rename too: `FetchedFaviconModel` → `DownloadedImageModel`, `FaviconUnavailableException` →
  `GuardedImageUnavailableException`, `FaviconRejectedException` → `GuardedImageRejectedException`. The proxy's
  `ImageUnavailableException` / `ImageRefusedException` are a different contract and stay separate.
- The parameter `$iconUrl` becomes `$imageUrl`; exception messages say "Image" instead of "Icon" (no test pins them).
- Properties holding it become `$downloader`; test doubles: `StubFaviconFetcher` → `StubGuardedImageDownloader`.
- Real favicon code (`FaviconResolver`, `CatalogFaviconWarmer`, `MissingFaviconResolver`, entity fields) keeps its names.
- No service id or alias names the class (autowired); `config/` and `docs/` (outside dated plans and specs) carry no
  reference. Dated specs stay untouched like the plans.

## Tasks

1. `git mv` the class, interface, model, exceptions, test and stub; rewrite namespaces and references with sed.
2. Rename the holding properties, parameters and test locals; update the docblocks.
3. Gates: cs, stan, tramp, md, native suite, infection:diff. (Lean rename rule: no MySQL leg, no PhpStorm pass.)
4. Simplify skill over the branch.
