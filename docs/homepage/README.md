# The project homepage

`index.html` is the public homepage for simple-feed-reader: a single static page,
no build step, no dependencies. Open it straight from the checkout to preview it.

## Screenshots have one source

The page shows the screenshots in [`docs/screenshots/`](../screenshots) through
the `screenshots` symlink in this folder, so there is nothing to copy or keep in
sync. Retake a screenshot there and the page shows the new one; the deploy below
runs on its own when the screenshots or the page change on `develop`.

The symlink is the reason the page lives one level below `docs/` rather than in
it: `cp -RL` at deploy time turns the link into a real folder, and the staged
site is a plain tree with nothing outside it.

## Deployment

`.github/workflows/deploy-homepage.yml` mirrors this folder to a subfolder of
lars-pohlmann.de over SFTP, on every push to `develop` that touches
`docs/homepage/` or `docs/screenshots/`, and on demand from the Actions tab.
It is the maintainer's personal deployment, like `deploy/strato/`; nothing
else in the repository depends on it.

It needs four repository secrets, named after the markdownobserver site's deploy
so the same values can be pasted across:

| Secret | Value |
|---|---|
| `WEB_FTP_SERVER` | the SFTP host |
| `WEB_FTP_USERNAME` | the SFTP user |
| `WEB_FTP_PASSWORD` | its password |
| `WEB_FTP_SERVER_DIR` | **this page's own subfolder** of the docroot, e.g. `larspohlmann/simplefeedreader` |

### Why nothing overwrites anything

Three deployments share one docroot on the host. Each stays in its lane by
construction, not by timing:

- **This workflow** mirrors with `--delete` into `WEB_FTP_SERVER_DIR` only.
  lftp prunes inside the directory it is pointed at and nowhere else, so the
  portfolio homepage and the sibling project folders are out of reach. The
  workflow refuses an empty or root `WEB_FTP_SERVER_DIR`, which would widen
  that to the whole docroot.
- **The markdownobserver site** mirrors its `webpage/` into its own subfolder
  the same way, so it cannot reach this one.
- **The portfolio homepage** (`larspohlmann/homepage`) mirrors its repository
  root into the docroot with `--delete`, and keeps the project folders alive
  only by excluding each one by name. **It must exclude this folder too**, or
  its next deploy removes the page. The one-line change in its
  `.github/workflows/deploy.yml`, with the name matching `WEB_FTP_SERVER_DIR`:

  ```diff
  -            printf 'mirror --reverse --delete --verbose -X ".git/" -X ".git*" -X ".github/" -X ".claude/" -X ".idea/" -X "CLAUDE.md" -X ".gitignore" -X "*.bck" -X "inseason/" -X "markdownobserver/" -X "simplesobertracker/" -X "smbwifianalyzer/" -X "reader" . %s\n' "$SFTP_SERVER_DIR"
  +            printf 'mirror --reverse --delete --verbose -X ".git/" -X ".git*" -X ".github/" -X ".claude/" -X ".idea/" -X "CLAUDE.md" -X ".gitignore" -X "*.bck" -X "inseason/" -X "markdownobserver/" -X "simplefeedreader/" -X "simplesobertracker/" -X "smbwifianalyzer/" -X "reader" . %s\n' "$SFTP_SERVER_DIR"
  ```

  Add the exclusion there before the first deploy here, since that repository
  deploys on every tag.
