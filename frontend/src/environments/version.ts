// src/environments/version.ts
//
// Rewritten at build time by scripts/stamp-version.sh — on the release runner
// for the Strato deploy, and inside the web image build for a Docker install
// (issue #500) — so the deployed bundle carries the tag it was cut from. The
// placeholders below are what a local build reports — do not hand-edit them to
// a real tag.
//
// It is committed rather than generated so a fresh clone builds, serves and
// tests with no generation step in front of it.
export const buildVersion = {
  version: 'dev',
  commit: 'local',
  builtAt: '',
};

/** What both halves report when no release build produced them. */
export const DEVELOPMENT_VERSION = 'dev';
