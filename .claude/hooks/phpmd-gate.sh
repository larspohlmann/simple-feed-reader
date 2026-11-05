#!/usr/bin/env bash
# PHPMD count-dodge gate (#2025). A PHPMD finding points at a design weakness; a new class
# that only holds a flagged class's overflow silences it without fixing anything, and no
# static rule can tell that from a real extraction. So while a finding is open, a commit
# that brings new backend/src classes is refused until Lars approves them in a message of his own.
#
#   record   PostToolUseFailure on Bash: a PHPMD run opens or closes the finding (md exits 2 on findings)
#   gate     PreToolUse on Bash: a git commit with unapproved new src classes is denied
#   approve  UserPromptSubmit: a prompt that starts with "approve classes" approves the classes the gate named
#   settle   PostToolUse on Bash: a PHPMD run records; a commit reports src classes that reached HEAD
#            unapproved, then forgets a closed finding or re-baselines an open one
#
# The gate denies rather than asks: in auto mode a hook's "ask" let the commit straight through
# (2026-11-05). UserPromptSubmit also carries messages from other sessions, subagents and the
# harness, so the phrase must open the prompt and no harness wrapper may appear in it: a relayed
# report that merely mentioned the phrase once approved a class without Lars (2026-11-05).
# The gate runs before the command, so a class created inside the commit's own command slips past
# it; settle catches that from HEAD and tells Lars. Commands match only at command position, so a
# commit message that merely names PHPMD or git commit triggers nothing.
#
# State: <git dir>/phpmd-gate.json = {open, findings, snapshot, pending}; shared by every session on the checkout.
# shellcheck disable=SC2016 # the single-quoted $names are jq variables, not shell ones
set -u

mode=${1:-}
input=$(cat)
command=$(jq -r '.tool_input.command // ""' <<<"$input")
event=$(jq -r '.hook_event_name // ""' <<<"$input")
root=${CLAUDE_PROJECT_DIR:-$(jq -r '.cwd // "."' <<<"$input")}
git_dir=$(git -C "$root" rev-parse --absolute-git-dir 2>/dev/null) || exit 0
state="$git_dir/phpmd-gate.json"

at_command_start="(^|[;&|(])[[:space:]]*([^;&|\"']*[[:space:]])?"
runs_phpmd() {
    grep -Eq "(^|[;&|(][[:space:]]*|/)phpmd([[:space:]]|\$)" <<<"$command" \
        || grep -Eq "${at_command_start}composer([[:space:]]+-[^[:space:]]+)*[[:space:]]+md([[:space:]]|\$|[;&|)])" <<<"$command"
}
runs_commit() {
    grep -Eq "${at_command_start}git([[:space:]]+-[^[:space:]]+([[:space:]]+[^-;&|[:space:]][^;&|[:space:]]*)?)*[[:space:]]+commit([[:space:]]|\$|[;&|)])" <<<"$command"
}

as_json_list() { jq -R . | jq -sc 'map(select(. != ""))'; }
source_files() { git -C "$root" ls-files -co --exclude-standard -- backend/src | grep '\.php$' | sort -u; }
committed_files() { git -C "$root" ls-tree -r --name-only HEAD -- backend/src | grep '\.php$' | sort -u; }
state_list() { jq -r ".$1[]?" "$state" | sort -u; }
update_state() { jq "$@" "$state" >"$state.tmp" && mv "$state.tmp" "$state"; }

record() {
    local output findings
    output=$(jq -r 'del(.tool_input) | [.. | strings] | join("\n")' <<<"$input")
    findings=$(grep -Eo '[^[:space:]]*/src/[^[:space:]]+\.php:[0-9]+[[:space:]]+[A-Za-z]+' <<<"$output" \
        | sed -E 's#^.*/src/##; s#\.php:[0-9]+[[:space:]]+# #' | sort -u)

    if [ -z "$findings" ]; then
        [ -f "$state" ] && update_state '.open = false'
        return 0
    fi

    if [ -f "$state" ]; then
        update_state --arg findings "$findings" '.open = true | .findings = ($findings | split("\n"))'
    else
        jq -n --argjson snapshot "$(source_files | as_json_list)" --arg findings "$findings" \
            '{open: true, findings: ($findings | split("\n")), snapshot: $snapshot, pending: []}' >"$state"
    fi

    jq -n --arg event "$event" --arg findings "$findings" '{hookSpecificOutput: {hookEventName: $event, additionalContext: (
        "PHPMD finding (class rule): " + ($findings | split("\n") | join(", ")) + ". "
        + "The limit points at a design weakness: name the concern the flagged class mixes and fix that design, "
        + "offering Lars the options with their cost. Do not move methods or constructor parameters into a new "
        + "class just to get under the count. Until PHPMD is clean and committed, a git commit that adds "
        + "backend/src classes is refused until Lars approves the new classes.")}}'
}

gate() {
    [ -f "$state" ] || return 0
    local added
    added=$(comm -13 <(state_list snapshot) <(source_files))
    [ -n "$added" ] || return 0
    update_state --argjson added "$(as_json_list <<<"$added")" '.pending = $added'
    jq -n --arg added "$added" --arg findings "$(jq -r '.findings | join(", ")' "$state")" \
        '{hookSpecificOutput: {hookEventName: "PreToolUse", permissionDecision: "deny", permissionDecisionReason: (
            "PHPMD count-dodge gate (#2025): new backend/src classes since the PHPMD finding on "
            + $findings + ": " + ($added | split("\n") | join(", "))
            + ". Show Lars why each is a real concern of its own, not a container for the flagged class'"'"'s "
            + "overflow. The commit goes through once Lars replies with \"approve classes\".")}}'
}

approve() {
    [ -f "$state" ] || return 0
    jq -e '(.prompt // "") as $prompt
        | ($prompt | test("\\A\\s*approve classes\\b"; "i"))
          and ($prompt | test("<(cross-session-message|task-notification|agent-message|system-reminder|ci-monitor-event|side-sessions-event)|Another Claude session sent a message") | not)' \
        <<<"$input" >/dev/null || return 0
    local pending
    pending=$(state_list pending)
    [ -n "$pending" ] || return 0
    update_state '.snapshot = (.snapshot + .pending | unique) | .pending = []'
    jq -n --arg pending "$pending" '{hookSpecificOutput: {hookEventName: "UserPromptSubmit", additionalContext: (
        "PHPMD count-dodge gate (#2025): Lars approved these new classes: " + ($pending | split("\n") | join(", ")))}}'
}

settle() {
    [ -f "$state" ] || return 0
    local committed unapproved
    committed=$(committed_files)
    unapproved=$(comm -13 <(state_list snapshot) - <<<"$committed")

    if [ "$(jq -r '.open' "$state")" = "false" ]; then
        rm -f "$state"
    else
        update_state --argjson committed "$(as_json_list <<<"$committed")" \
            '.snapshot = (.snapshot + $committed | unique) | .pending = ((.pending // []) - $committed)'
    fi

    [ -n "$unapproved" ] || return 0
    jq -n --arg unapproved "$unapproved" '($unapproved | split("\n") | join(", ")) as $files | {
        systemMessage: ("PHPMD count-dodge gate (#2025): committed without your approval while a PHPMD finding was open: " + $files),
        hookSpecificOutput: {hookEventName: "PostToolUse", additionalContext: (
            "These backend/src classes reached HEAD while a PHPMD finding was open, without Lars'"'"'s approval "
            + "(created inside the commit command): " + $files + ". Tell Lars and justify each one.")}}'
}

case "$mode" in
    record) runs_phpmd && record ;;
    gate) runs_commit && gate ;;
    approve) approve ;;
    settle) if runs_phpmd; then record; elif runs_commit; then settle; fi ;;
    *) echo "phpmd-gate.sh: unknown mode '$mode'" >&2; exit 1 ;;
esac
exit 0
