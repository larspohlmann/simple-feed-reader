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

`.github/workflows/deploy-homepage.yml` rsyncs this folder over SSH into
`~/larspohlmann/simplefeedreader` on the Strato host, a subfolder of the
portfolio docroot beside the app's own `reader` mount
([`deploy/strato/README.md`](../../deploy/strato/README.md)). It runs on every
push to `develop` that touches `docs/homepage/` or `docs/screenshots/`, and on
demand from the Actions tab. It is the maintainer's personal deployment, like
`deploy/strato/`; nothing else in the repository depends on it.

It reaches the host the way the app deploy does and needs no secrets of its
own: `STRATO_SSH_HOST`, `STRATO_SSH_USER`, `STRATO_SSH_KEY` and
`STRATO_KNOWN_HOSTS`, which already exist. The target folder is the
`HOMEPAGE_PATH` value at the top of the workflow, not a secret: the docroot is
public knowledge in the Strato README, and the name has to match the exclusion
below.

### Why nothing overwrites anything

Three deployments share one docroot on the host. Each stays in its lane by
construction, not by timing:

- **This workflow** rsyncs with `--delete` into `HOMEPAGE_PATH` only. rsync
  prunes inside the directory it is pointed at and nowhere else, so the
  portfolio homepage and the sibling project folders are out of reach. The
  workflow refuses a path that is not a subfolder of the docroot, which would
  widen that to the docroot or to the home directory.
- **The markdownobserver site** mirrors its `webpage/` into its own subfolder
  the same way, so it cannot reach this one.
- **The portfolio homepage** (`larspohlmann/homepage`) mirrors its repository
  root into the docroot with `--delete`, and keeps the project folders alive
  only by excluding each one by name. **It must exclude this folder too**, or
  its next deploy removes the page. The one-line change in its
  `.github/workflows/deploy.yml`, with the name matching `HOMEPAGE_PATH`:

  ```diff
  -            printf 'mirror --reverse --delete --verbose -X ".git/" -X ".git*" -X ".github/" -X ".claude/" -X ".idea/" -X "CLAUDE.md" -X ".gitignore" -X "*.bck" -X "inseason/" -X "markdownobserver/" -X "simplesobertracker/" -X "smbwifianalyzer/" -X "reader" . %s\n' "$SFTP_SERVER_DIR"
  +            printf 'mirror --reverse --delete --verbose -X ".git/" -X ".git*" -X ".github/" -X ".claude/" -X ".idea/" -X "CLAUDE.md" -X ".gitignore" -X "*.bck" -X "inseason/" -X "markdownobserver/" -X "simplefeedreader/" -X "simplesobertracker/" -X "smbwifianalyzer/" -X "reader" . %s\n' "$SFTP_SERVER_DIR"
  ```

  Add the exclusion there before the first deploy here, since that repository
  deploys on every tag.
