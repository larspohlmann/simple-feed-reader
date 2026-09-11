# Grafana Phase 4 — containers, provisioning & installer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Let an operator run a self-hosted Loki + Grafana stack: always on in dev, an optional compose profile in Docker-prod driven by the installer, with Grafana provisioned ready to read logs.

**Architecture:** Add `loki` + `grafana` services to both compose files (dev: no profile, always on like `meilisearch`; prod: `profiles: ["grafana"]` like the Meilisearch service, driven by a `.env.prod` value). Grafana is provisioned from a mounted tree (Loki datasource + one starter dashboard). The installer clones the Meilisearch path end to end: a `prod_uses_grafana` predicate, an extended `prod_compose_profiles`, `use_grafana`/`use_no_grafana` appliers, and a `configure_grafana` prompt asked in every non-quick path plus `prod-configure.sh`, mentioned before the package prompt.

**Tech Stack:** Docker Compose, Grafana Loki (single-binary), Grafana, bash (`scripts/lib.sh`), the project's `scripts/test/*.test.sh` harness.

**Spec:** `docs/superpowers/specs/2026-09-11-grafana-observability-design.md`

**Depends on:** Phase 2 (the app pushes to `GRAFANA_LOKI_PUSH_URL`), Phase 3 (the admin settings read `GRAFANA_LOKI_PUSH_URL` / `GRAFANA_URL` as env defaults).

## Global Constraints

- Mirror the **Meilisearch** service exactly as the prod-optional-service precedent: `profiles: ["grafana"]`, `restart: unless-stopped`, a named volume, no `depends_on`, no published port beyond what the operator needs to view Grafana.
- Dev: **always on, no profile, no opt-out** (per the user) — exactly like the dev `meilisearch` service.
- The installer's package model (S/M/L/Q/C) is NOT changed. Grafana is a **dedicated yes/no** asked in every non-quick path (S/M/L/C) and in `prod-configure.sh`, and **mentioned before the package prompt**. Quick (`Q`) applies `use_no_grafana` (a written default, never skipped — the #453 rule).
- The profile is driven by an `.env.prod` value exactly as `MEILISEARCH_URL` drives `meilisearch`: `prod_uses_grafana` is true iff `GRAFANA_LOKI_PUSH_URL` is non-empty.
- CI shellcheck fails on ANY finding — new bash must be shellcheck-clean.
- `scripts/test/*.test.sh` bash unit tests cover new installer logic (mirror `configure-search-engine.test.sh`).
- Loki retention 14 days; a generated `GF_SECURITY_ADMIN_PASSWORD`, shown once.
- Every service blocks validated with `docker compose config` (dev) and `docker compose -f docker-compose.prod.yml --env-file .env.prod config` (prod, both profiles on and off).

---

### Task 1: Loki config + Grafana provisioning files

**Files:**
- Create: `docker/loki/loki-config.yaml`
- Create: `docker/grafana/provisioning/datasources/loki.yaml`
- Create: `docker/grafana/provisioning/dashboards/dashboards.yaml`
- Create: `docker/grafana/dashboards/application-logs.json`

**Interfaces:** Mounted read-only into the containers by Tasks 2 & 3. Loki listens on `:3100`; Grafana auto-loads the Loki datasource and the dashboard.

- [ ] **Step 1: Loki config with 14-day retention** (`docker/loki/loki-config.yaml`)

```yaml
auth_enabled: false

server:
  http_listen_port: 3100
  log_level: warn

common:
  instance_addr: 127.0.0.1
  path_prefix: /loki
  storage:
    filesystem:
      chunks_directory: /loki/chunks
      rules_directory: /loki/rules
  replication_factor: 1
  ring:
    kvstore:
      store: inmemory

schema_config:
  configs:
    - from: 2020-10-24
      store: tsdb
      object_store: filesystem
      schema: v13
      index:
        prefix: index_
        period: 24h

limits_config:
  retention_period: 336h  # 14 days
  reject_old_samples: true
  reject_old_samples_max_age: 336h

compactor:
  working_directory: /loki/compactor
  retention_enabled: true
  delete_request_store: filesystem
```

Verify the exact keys against the pinned Loki image version chosen in Task 2 (`grafana/loki:3.x`); Loki config keys shift between majors. Boot it once in Task 2's smoke and fix any `failed parsing config` error the container logs.

- [ ] **Step 2: Grafana Loki datasource** (`docker/grafana/provisioning/datasources/loki.yaml`)

```yaml
apiVersion: 1

datasources:
  - name: Loki
    type: loki
    access: proxy
    url: http://loki:3100
    isDefault: true
    editable: false
```

- [ ] **Step 3: Dashboard provider** (`docker/grafana/provisioning/dashboards/dashboards.yaml`)

```yaml
apiVersion: 1

providers:
  - name: 'simple-feed-reader'
    orgId: 1
    folder: ''
    type: file
    disableDeletion: false
    editable: true
    options:
      path: /var/lib/grafana/dashboards
```

- [ ] **Step 4: Starter dashboard** (`docker/grafana/dashboards/application-logs.json`)

A minimal, valid Grafana dashboard with a `level` and `channel` template variable, a log-volume timeseries by level, an error+critical stat, and a live logs panel. Keep it compact but valid; verify it loads in Task 2's smoke (Grafana logs "failed to load dashboard" if the JSON is malformed). Use `${DS_LOKI}`-free explicit datasource `{"type":"loki","uid":"loki"}` by giving the datasource a fixed uid (add `uid: loki` to the datasource yaml in Step 2). Panels:
- `timeseries` titled "Log volume by level": target expr `sum by (level) (count_over_time({app="simple-feed-reader"} | json | level=~"$level" | channel=~"$channel" [$__interval]))`.
- `stat` titled "Errors + critical": expr `sum(count_over_time({app="simple-feed-reader"} | json | level=~"error|critical" [$__range]))`.
- `logs` titled "Logs": expr `{app="simple-feed-reader"} | json | level=~"$level" | channel=~"$channel"`.
- Two `query`-type template variables `level` and `channel` over `label_values` (or custom multi-select) with `includeAll`.

Write the JSON by hand following the current Grafana dashboard schema; the smoke in Task 2 is the acceptance test.

- [ ] **Step 5: Commit**

```bash
git add docker/loki docker/grafana
git commit -m "feat(#983): add Loki config and Grafana provisioning for log dashboards"
```

---

### Task 2: dev compose — Loki + Grafana always on

**Files:**
- Modify: `docker-compose.yml`

- [ ] **Step 1: Add the services** (after `meilisearch`, before `frontend`). No profile — always on, like `meilisearch`.

```yaml
  # Log store, always on in dev (like meilisearch): the app pushes its JSON logs
  # here and Grafana reads them. No depends_on — the app fails open if Loki is
  # down (see LokiClient).
  loki:
    image: grafana/loki:3.4.2
    command: ["-config.file=/etc/loki/loki-config.yaml"]
    volumes:
      - ./docker/loki/loki-config.yaml:/etc/loki/loki-config.yaml:ro
      - loki-data:/loki
    ports:
      - "127.0.0.1:3100:3100"

  grafana:
    image: grafana/grafana:11.5.2
    environment:
      GF_SECURITY_ADMIN_PASSWORD: "admin"
      GF_AUTH_ANONYMOUS_ENABLED: "false"
      GF_USERS_ALLOW_SIGN_UP: "false"
    volumes:
      - ./docker/grafana/provisioning:/etc/grafana/provisioning:ro
      - ./docker/grafana/dashboards:/var/lib/grafana/dashboards:ro
      - grafana-data:/var/lib/grafana
    ports:
      - "127.0.0.1:3000:3000"
    depends_on:
      - loki
```

- [ ] **Step 2: Point the dev app at Loki.** Add to BOTH the `php` and `worker` `environment:` blocks:

```yaml
      GRAFANA_LOKI_PUSH_URL: "http://loki:3100/loki/api/v1/push"
      GRAFANA_URL: "http://localhost:3000"
```

- [ ] **Step 3: Add the named volumes** to the `volumes:` block:

```yaml
  loki-data:
  grafana-data:
```

- [ ] **Step 4: Validate + smoke**

```bash
docker compose config >/dev/null && echo "dev compose valid"
docker compose up -d loki grafana
sleep 5
docker compose logs loki --tail=30    # must NOT contain "failed parsing config"
docker compose logs grafana --tail=30 # must NOT contain "failed to load dashboard" / provisioning errors
curl -sf http://localhost:3100/ready && echo "loki ready"
curl -sf -u admin:admin http://localhost:3000/api/datasources | grep -q Loki && echo "grafana has Loki datasource"
```

Fix any config/JSON errors surfaced here (this is Task 1's real acceptance test). Then optionally push a test line and confirm it lands:

```bash
curl -sf -H "Content-Type: application/json" -XPOST http://localhost:3100/loki/api/v1/push \
  --data-binary '{"streams":[{"stream":{"app":"simple-feed-reader","level":"info"},"values":[["'"$(date +%s)"'000000000","{\"message\":\"smoke\"}"]]}]}' \
  && curl -sf -G http://localhost:3100/loki/api/v1/query_range --data-urlencode 'query={app="simple-feed-reader"}' | grep -q smoke && echo "loki round-trip ok"
```

- [ ] **Step 5: Commit**

```bash
git add docker-compose.yml
git commit -m "feat(#983): run Loki and Grafana in the dev stack"
```

---

### Task 3: prod compose — Loki + Grafana behind the grafana profile

**Files:**
- Modify: `docker-compose.prod.yml`

- [ ] **Step 1: Add the env vars to `x-app-environment`** (so php + worker see them; empty means "no local container / not shipping"):

```yaml
  GRAFANA_LOKI_PUSH_URL: ${GRAFANA_LOKI_PUSH_URL:-}
  GRAFANA_LOKI_USERNAME: ${GRAFANA_LOKI_USERNAME:-}
  GRAFANA_LOKI_TOKEN: ${GRAFANA_LOKI_TOKEN:-}
  GRAFANA_URL: ${GRAFANA_URL:-}
```

- [ ] **Step 2: Add the services** (after `meilisearch`), mirroring its profile treatment. No published port on Loki; Grafana published on a bindable, configurable port.

```yaml
  # Log store + dashboard, behind the grafana profile: an install that declined
  # observability, or cannot run more containers, never starts these.
  # prod_compose_profiles in scripts/lib.sh enables this profile exactly while
  # GRAFANA_LOKI_PUSH_URL in .env.prod is non-empty. No depends_on: the app
  # fails open when Loki is absent (LokiClient).
  loki:
    image: grafana/loki:3.4.2
    profiles: ["grafana"]
    restart: unless-stopped
    command: ["-config.file=/etc/loki/loki-config.yaml"]
    volumes:
      - ./docker/loki/loki-config.yaml:/etc/loki/loki-config.yaml:ro
      - loki-data:/loki

  grafana:
    image: grafana/grafana:11.5.2
    profiles: ["grafana"]
    restart: unless-stopped
    environment:
      GF_SECURITY_ADMIN_PASSWORD: ${GRAFANA_ADMIN_PASSWORD:?set it in .env.prod}
      GF_AUTH_ANONYMOUS_ENABLED: "false"
      GF_USERS_ALLOW_SIGN_UP: "false"
      GF_SERVER_ROOT_URL: ${GRAFANA_URL:-}
    volumes:
      - ./docker/grafana/provisioning:/etc/grafana/provisioning:ro
      - ./docker/grafana/dashboards:/var/lib/grafana/dashboards:ro
      - grafana-data:/var/lib/grafana
    ports:
      - "${WEB_BIND_ADDRESS:-0.0.0.0}:${GRAFANA_PORT:-3000}:3000"
```

Note the `GF_SECURITY_ADMIN_PASSWORD: ${GRAFANA_ADMIN_PASSWORD:?...}` uses `:?` — but compose resolves the whole file before filtering profiles, so a `:?` guard would abort every install even with grafana OFF (the exact trap the mysql/meilisearch comments describe). Use `${GRAFANA_ADMIN_PASSWORD:-}` instead and rely on the installer to generate it whenever the profile is on; add a one-line comment saying why it is permissive, mirroring the `INSTANCE_SECRET_KEY` comment.

- [ ] **Step 3: Add the named volumes**:

```yaml
  loki-data:
  grafana-data:
```

- [ ] **Step 4: Validate both profile states**

```bash
# profile OFF (no GRAFANA_* set): grafana/loki absent, file still valid
GRAFANA_LOKI_PUSH_URL= docker compose -f docker-compose.prod.yml --env-file .env.prod config >/dev/null && echo "prod off valid"
# profile ON:
COMPOSE_PROFILES=grafana GRAFANA_ADMIN_PASSWORD=x GRAFANA_URL=http://localhost:3000 \
  docker compose -f docker-compose.prod.yml --env-file .env.prod config | grep -q 'grafana/grafana' && echo "prod on includes grafana"
```

(Use a throwaway `.env.prod` or the example if none exists; the point is the file parses in both states.)

- [ ] **Step 5: Commit**

```bash
git add docker-compose.prod.yml
git commit -m "feat(#983): add optional Loki+Grafana profile to the prod stack"
```

---

### Task 4: Installer — predicate, profile, appliers, prompt

**Files:**
- Modify: `scripts/lib.sh`
- Modify: `.env.prod.example`
- Test: `scripts/test/configure-grafana.test.sh` (new; mirror `scripts/test/configure-search-engine.test.sh`)

**Interfaces:** `prod_uses_grafana`, `use_grafana`, `use_no_grafana`, `configure_grafana`, `current_grafana_choice`, `stop_disabled_grafana_containers`; `prod_compose_profiles` appends `grafana`.

- [ ] **Step 1: Read `scripts/test/configure-search-engine.test.sh`** and mirror its harness for a new `configure-grafana.test.sh`. Cases: `use_grafana` sets `GRAFANA_LOKI_PUSH_URL` and `GRAFANA_URL` and generates `GRAFANA_ADMIN_PASSWORD`; `use_no_grafana` clears the push URL; `prod_uses_grafana` reflects the push URL; `prod_compose_profiles` includes `grafana` only when on and composes correctly with `mysql`/`meilisearch`; `configure_grafana` yes/no branches. Write it to fail first.

- [ ] **Step 2: Add the predicate + profile** (near `prod_uses_search_engine`, ~line 404):

```bash
# Whether the operator asked for the self-hosted Loki+Grafana stack. A non-empty
# GRAFANA_LOKI_PUSH_URL means yes -- docker-compose.prod.yml puts loki and
# grafana behind that profile, the same way prod_uses_search_engine drives
# meilisearch.
prod_uses_grafana() {
  [ -n "$(trim_whitespace "$(env_prod_get GRAFANA_LOKI_PUSH_URL)")" ]
}
```

Extend `prod_compose_profiles` (append after the meilisearch block, before the `printf`):

```bash
  if prod_uses_grafana; then
    profiles="${profiles:+${profiles},}grafana"
  fi
```

- [ ] **Step 3: Add the appliers** (near `use_bundled_search_engine`, ~line 1603):

```bash
# The internal Loki push URL and a local viewing URL become the effective
# defaults the admin settings show for the local container; the admin overrides
# the viewing URL with the externally reachable address. Generate the Grafana
# admin password once if it is not already set, the same permissive way
# ensure_ai_key_secret handles INSTANCE_SECRET_KEY.
use_grafana() {
  env_prod_set GRAFANA_LOKI_PUSH_URL 'http://loki:3100/loki/api/v1/push'
  env_prod_set GRAFANA_URL 'http://localhost:3000'
  if [ -z "$(trim_whitespace "$(env_prod_get GRAFANA_ADMIN_PASSWORD)")" ]; then
    env_prod_set GRAFANA_ADMIN_PASSWORD "$(generate_secret)"
  fi
}

use_no_grafana() {
  env_prod_set GRAFANA_LOKI_PUSH_URL ''
  env_prod_set GRAFANA_URL ''
}
```

- [ ] **Step 4: Add the prompt** (mirror `configure_search_engine`, ~line 1554, and its `current_search_engine_choice` at ~1596):

```bash
current_grafana_choice() {
  if prod_uses_grafana; then
    printf 'y'
  else
    printf 'n'
  fi
}

# Ask whether to run the self-hosted Loki+Grafana log dashboard. Like
# configure_search_engine, a silent no-op lands on the passed default; the
# caller passes the current choice so pressing return never reverses it.
configure_grafana() {
  local default=$1 answer
  if ! can_prompt; then
    [ "${default}" = 'y' ] && use_grafana || use_no_grafana
    return 0
  fi
  answer=$(prompt_with_default 'Run a Grafana log dashboard (Loki + Grafana containers)? [y/N]' "${default}")
  case "${answer}" in
    [yY]*) use_grafana ; say 'Grafana enabled. The admin password is in .env.prod (GRAFANA_ADMIN_PASSWORD).' ;;
    *) use_no_grafana ;;
  esac
}
```

Match the real signature/idiom of `configure_search_engine` (it takes a default and uses `apply_search_engine_choice`); adapt the `[yY]*)` branch to whatever yes-detection helper the file already uses so behaviour is consistent with the engine question.

- [ ] **Step 5: The mention before the package prompt.** Extend `package_question_follow_up` (~1351) with one sentence so it is on screen before the package is chosen:

```bash
  tell "  ${_c_dim}All but Q can also add a Grafana log dashboard (Loki + Grafana),${_c_reset}"
  tell "  ${_c_dim}asked after the package.${_c_reset}"
```

- [ ] **Step 6: `stop_disabled_grafana_containers`** — mirror `stop_disabled_search_engine_container` (~465) so a re-configure that turns Grafana off removes the containers (keeping the volumes). `prod-start.sh` calls it after `up`; add that call next to the search-engine one (find it in `prod-start.sh`).

- [ ] **Step 7: `.env.prod.example`** — add a documented block:

```
###> grafana observability (optional; scripts/install.sh sets these) ###
# Non-empty GRAFANA_LOKI_PUSH_URL turns on the loki+grafana compose profile and
# makes the app ship its logs there. GRAFANA_URL is the viewing URL shown in the
# admin settings; override it there with the address you actually reach Grafana
# on. GRAFANA_ADMIN_PASSWORD is generated by the installer.
GRAFANA_LOKI_PUSH_URL=
GRAFANA_LOKI_USERNAME=
GRAFANA_LOKI_TOKEN=
GRAFANA_URL=
GRAFANA_ADMIN_PASSWORD=
# GRAFANA_PORT=3000
###< grafana observability ###
```

- [ ] **Step 8: Run the bash tests + shellcheck**

```bash
bash scripts/test/configure-grafana.test.sh
shellcheck scripts/lib.sh scripts/test/configure-grafana.test.sh
```
Expected: tests pass; shellcheck reports NOTHING (CI fails on any finding).

- [ ] **Step 9: Commit**

```bash
git add scripts/lib.sh scripts/test/configure-grafana.test.sh .env.prod.example
git commit -m "feat(#983): installer support for the optional Grafana profile"
```

---

### Task 5: Wire the prompt into the install flows

**Files:**
- Modify: `scripts/install.sh`
- Modify: `scripts/prod-configure.sh`
- Modify: `scripts/prod-start.sh` (the `stop_disabled_grafana_containers` call — if not already added in Task 4 Step 6)

- [ ] **Step 1: install.sh** — in the non-quick branch, after `configure_mail` (line ~217), add `configure_grafana 'n'` (fresh install has no prior choice, default no). In the quick branch (after `use_no_mail`, line ~205) add `use_no_grafana` (apply the default, never skip — the #453 rule).

- [ ] **Step 2: prod-configure.sh** — after `configure_mail` (line ~60) add `configure_grafana "$(current_grafana_choice)"` so a re-configure can turn it on/off and pressing return keeps the current state.

- [ ] **Step 3: Verify the flows headlessly** where possible: run `scripts/test/configure-grafana.test.sh` again, and if an install smoke harness exists (`scripts/test/previous-prod-install.test.sh` style), extend or add a case that a `C` install answering yes to Grafana writes `GRAFANA_LOKI_PUSH_URL` and that `prod_compose_profiles` then contains `grafana`.

- [ ] **Step 4: Commit**

```bash
git add scripts/install.sh scripts/prod-configure.sh scripts/prod-start.sh
git commit -m "feat(#983): ask about Grafana in install and reconfigure flows"
```

---

### Task 6: Docs

**Files:**
- Modify: `README.md` (the package/observability section — if it lists what each package installs, add the Grafana option honestly: optional, off by default, not part of S/M/L/Q)
- Modify: `docs/local-docker.md` (note Loki+Grafana run in dev on :3000/:3100)
- Modify: `CLAUDE.md` (the Docker stack section / a one-line note that dev now runs Grafana; keep it to one line)

- [ ] **Step 1:** Add concise, accurate notes. Do not oversell — Grafana viewing is link-out, tracing is Phase 5. One or two lines each.

- [ ] **Step 2: Commit**

```bash
git add README.md docs/local-docker.md CLAUDE.md
git commit -m "docs(#983): document the optional Grafana log dashboard"
```

---

## Phase-4 exit checks

```bash
docker compose config >/dev/null && echo dev-ok
docker compose -f docker-compose.prod.yml --env-file .env.prod config >/dev/null && echo prod-ok
bash scripts/test/configure-grafana.test.sh
shellcheck scripts/lib.sh scripts/install.sh scripts/prod-configure.sh scripts/prod-start.sh scripts/test/configure-grafana.test.sh
```

Plus the dev live smoke from Task 2 Step 4 (Loki ready, Grafana has the datasource, round-trip). All green.

## Self-review notes

- Spec coverage: implements "Phase 4" — dev always-on Loki+Grafana, prod profile-gated clone of Meilisearch, provisioning (datasource + starter dashboard), 14-day retention, generated admin password, installer prompt (mentioned before the package, asked in every non-quick path + reconfigure), env wiring so Phase-3 settings prefill from the container.
- Not covered: Tempo + tracing (Phase 5) — Task 3's grafana service and the profile are extended there.
- Type consistency: the env var the installer writes (`GRAFANA_LOKI_PUSH_URL`, `GRAFANA_URL`) are exactly the names Phase 3's `GrafanaSettings` service reads as defaults, and `prod_uses_grafana` keys off the same `GRAFANA_LOKI_PUSH_URL`.
