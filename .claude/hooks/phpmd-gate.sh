#!/usr/bin/env bash
# PHPMD count-dodge gate (#2025). A PHPMD finding points at a design weakness; a new class
# that only holds a flagged class's overflow silences it without fixing anything, and no
# static rule can tell that from a real extraction. So while a finding is open, a commit
# that brings new backend/src classes is refused until the user approves them in a message of their own.
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
# report that merely mentioned the phrase once approved a class without the user (2026-11-05).
# The gate runs before the command, so a class created inside the commit's own command slips past
# it; settle catches that from HEAD and tells the user. Commands match only at command position, so a
# commit message that merely names PHPMD or git commit triggers nothing.
#
# Each checkout, main or worktree, keeps its own state, and a command is judged in the checkout it
# runs in: its `cd`s and `git -C` from the hook's cwd. Judging the main checkout for every session
# denied a worktree commit over another session's classes (#2604). A directory change this cannot
# follow (a variable, a subshell, a pipeline) judges every checkout the command could reach instead.
#
# State: <git dir>/phpmd-gate.json = {open, findings, snapshot, pending}; shared by every session on the checkout.
# shellcheck disable=SC2016 # the single-quoted $names are jq variables, not shell ones
set -u

mode=${1:-}
input=$(cat)
command=$(jq -r '.tool_input.command // ""' <<<"$input")
event=$(jq -r '.hook_event_name // ""' <<<"$input")
session_dir=$(jq -r '.cwd // ""' <<<"$input")
session_dir=${session_dir:-${CLAUDE_PROJECT_DIR:-.}}
root=""
state=""

at_command_start="(^|[;&|(])[[:space:]]*([^;&|\"']*[[:space:]])?"
runs_phpmd() {
    grep -Eq "(^|[;&|(][[:space:]]*|/)phpmd([[:space:]]|\$)" <<<"$1" \
        || grep -Eq "${at_command_start}composer([[:space:]]+-[^[:space:]]+)*[[:space:]]+md([[:space:]]|\$|[;&|)])" <<<"$1"
}
runs_commit() {
    grep -Eq "${at_command_start}git([[:space:]]+-[^[:space:]]+([[:space:]]+[^-;&|[:space:]][^;&|[:space:]]*)?)*[[:space:]]+commit([[:space:]]|\$|[;&|)])" <<<"$1"
}

command_segments() { awk '{ gsub(/&&|\|\||[;|&]/, "\n&\n"); print }' <<<"$command"; }
shell_words() { xargs printf '%s\n' <<<"$1" 2>/dev/null; }
expands() { grep -Eq '[$`]' <<<"$1"; }
moves_dir() { grep -Eq '(^|[^[:alnum:]_./-])(cd|pushd|popd)([[:space:]]|$)' <<<"$1"; }
changes_git_location() { grep -Eq 'GIT_DIR|GIT_WORK_TREE|--git-dir|--work-tree|--chdir|--working-dir' <<<"$1"; }

resolve_dir() {
    local target=$2
    case "$target" in \~) target=$HOME ;; \~/*) target=$HOME/${target#\~/} ;; esac
    (CDPATH='' cd -- "$1" 2>/dev/null && CDPATH='' cd -- "$target" 2>/dev/null && pwd -P)
}

cd_target() {
    local words
    ! expands "$1" || return 1
    words=$(shell_words "$1") || return 1
    [ "$(wc -l <<<"$words")" -eq 2 ] && [ "$(head -1 <<<"$words")" = cd ] || return 1
    tail -1 <<<"$words" | grep -v '^-'
}

# The directory a matched git or PHPMD segment runs in, after its own -C; fails when it cannot tell.
segment_dir() {
    local segment=$1 dir=$2 head words word after_git=false take_next=false
    ! changes_git_location "$segment" || return 1
    if ! runs_commit "$segment"; then
        ! grep -Eq 'composer.*[[:space:]]-d' <<<"$segment" || return 1
        echo "$dir"
        return 0
    fi
    head=$(sed -E 's/[[:space:]]commit([[:space:]].*)?$//' <<<"$segment")
    ! expands "$head" || return 1
    words=$(shell_words "$head") || return 1
    while IFS= read -r word; do
        if $take_next; then dir=$(resolve_dir "$dir" "$word") || return 1; take_next=false
        elif [ "$word" = -C ]; then $after_git || return 1; take_next=true
        elif [ "$word" = git ]; then after_git=true
        fi
    done <<<"$words"
    echo "$dir"
}

# Every directory the matched command runs in; with a move it cannot follow before the match, also every
# directory the command could have reached, so an unreadable command is judged more strictly, never less.
command_dirs() {
    local matches=$1 dir=$session_dir segment target before="" cd_pending=false lost=false unsure=false reached=""
    while IFS= read -r segment; do
        case "$segment" in
            '&&' | '||' | ';' | '|' | '&')
                if $cd_pending && { [ "$segment" = '|' ] || [ "$segment" = '&' ]; }; then lost=true; fi
                cd_pending=false
                before=$segment
                continue
                ;;
        esac
        [ -n "${segment//[[:space:]]/}" ] || continue
        cd_pending=false
        if "$matches" "$segment"; then
            $lost && unsure=true
            segment_dir "$segment" "$dir" || unsure=true
        elif moves_dir "$segment"; then
            if [ "$before" != '||' ] && [ "$before" != '|' ] && target=$(cd_target "$segment") \
                && target=$(resolve_dir "$dir" "$target"); then
                dir=$target
                reached+="$dir"$'\n'
                cd_pending=true
            else
                lost=true
            fi
        fi
        before=""
    done < <(command_segments)
    $unsure || return 0
    printf '%s\n%s\n%s' "$session_dir" "${CLAUDE_PROJECT_DIR:-$session_dir}" "$reached"
}

worktree_dirs() {
    { git -C "$session_dir" worktree list --porcelain; git -C "${CLAUDE_PROJECT_DIR:-$session_dir}" worktree list --porcelain; } \
        2>/dev/null | sed -n 's/^worktree //p'
}

# One "<git dir><TAB><top level>" line per distinct checkout the given directories lie in.
checkouts_of() {
    local dir git_dir top
    while IFS= read -r dir; do
        [ -n "$dir" ] || continue
        git_dir=$(git -C "$dir" rev-parse --absolute-git-dir 2>/dev/null) || continue
        top=$(git -C "$dir" rev-parse --show-toplevel 2>/dev/null) || continue
        printf '%s\t%s\n' "$git_dir" "$top"
    done | sort -u -t "$(printf '\t')" -k1,1
}

as_json_list() { jq -R . | jq -sc 'map(select(. != ""))'; }
source_files() { git -C "$root" ls-files -co --exclude-standard -- backend/src | grep '\.php$' | sort -u; }
committed_files() { git -C "$root" ls-tree -r --name-only HEAD -- backend/src | grep '\.php$' | sort -u; }
state_list() { jq -r ".$1[]?" "$state" | sort -u; }
update_state() { jq "$@" "$state" >"$state.tmp" && mv "$state.tmp" "$state"; }

# Runs the step once per checkout listed on stdin, with $root and $state set; prints what each one printed.
in_each_checkout() {
    local git_dir
    while IFS=$'\t' read -r git_dir root; do
        state="$git_dir/phpmd-gate.json"
        case "$1" in
            record) record_in_checkout ;;
            gate) gate_in_checkout ;;
            approve) approve_in_checkout ;;
            settle) settle_in_checkout ;;
        esac
    done
}

read_findings() {
    jq -r 'del(.tool_input) | [.. | strings] | join("\n")' <<<"$input" \
        | grep -Eo '[^[:space:]]*/src/[^[:space:]]+\.php:[0-9]+[[:space:]]+[A-Za-z]+' \
        | sed -E 's#^.*/src/##; s#\.php:[0-9]+[[:space:]]+# #' | sort -u
}

record_in_checkout() {
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
}

record() {
    findings=$(read_findings)
    command_dirs runs_phpmd | checkouts_of | in_each_checkout record
    [ -n "$findings" ] || return 0
    jq -n --arg event "$event" --arg findings "$findings" '{hookSpecificOutput: {hookEventName: $event, additionalContext: (
        "PHPMD finding (class rule): " + ($findings | split("\n") | join(", ")) + ". "
        + "The limit points at a design weakness: name the concern the flagged class mixes and fix that design, "
        + "offering the user the options with their cost. Do not move methods or constructor parameters into a new "
        + "class just to get under the count. Until PHPMD is clean and committed, a git commit that adds "
        + "backend/src classes is refused until the user approves the new classes.")}}'
}

gate_in_checkout() {
    [ -f "$state" ] || return 0
    local added
    added=$(comm -13 <(state_list snapshot) <(source_files))
    [ -n "$added" ] || return 0
    update_state --argjson added "$(as_json_list <<<"$added")" '.pending = $added'
    jq -n --arg checkout "$root" --arg added "$added" --argjson findings "$(jq -c '.findings' "$state")" \
        '{checkout: $checkout, findings: $findings, added: ($added | split("\n"))}'
}

gate() {
    local denials
    denials=$(command_dirs runs_commit | checkouts_of | in_each_checkout gate | jq -sc .)
    [ "$denials" != "[]" ] || return 0
    jq -n --argjson denials "$denials" '{hookSpecificOutput: {hookEventName: "PreToolUse", permissionDecision: "deny", permissionDecisionReason: (
        "PHPMD count-dodge gate (#2025): "
        + ($denials | map("new backend/src classes in " + .checkout + " since the PHPMD finding on "
            + (.findings | join(", ")) + ": " + (.added | join(", "))) | join("; "))
        + ". Show the user why each is a real concern of its own, not a container for the flagged class'"'"'s "
        + "overflow. The commit goes through once the user starts a reply with \"approve classes\".")}}'
}

approve_in_checkout() {
    [ -f "$state" ] || return 0
    local pending
    pending=$(state_list pending)
    [ -n "$pending" ] || return 0
    update_state '.snapshot = (.snapshot + .pending | unique) | .pending = []'
    printf '%s\n' "$pending"
}

approve() {
    jq -e '(.prompt // "") as $prompt
        | ($prompt | test("\\A\\s*approve classes\\b"; "i"))
          and ($prompt | test("<(cross-session-message|task-notification|agent-message|system-reminder|ci-monitor-event|side-sessions-event)|Another Claude session sent a message") | not)' \
        <<<"$input" >/dev/null || return 0
    local approved approver
    approved=$(worktree_dirs | checkouts_of | in_each_checkout approve | paste -sd ',' - | sed 's/,/, /g')
    [ -n "$approved" ] || return 0
    approver=$(git -C "$session_dir" config user.name)
    [ -n "$approver" ] || approver="The user"
    jq -n --arg approved "$approved" --arg approver "$approver" '{hookSpecificOutput: {hookEventName: "UserPromptSubmit", additionalContext: (
        "PHPMD count-dodge gate (#2025): " + $approver + " approved these new classes: " + $approved)}}'
}

settle_in_checkout() {
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
    [ -z "$unapproved" ] || printf '%s\n' "$unapproved"
}

settle() {
    local unapproved
    unapproved=$(command_dirs runs_commit | checkouts_of | in_each_checkout settle | paste -sd ',' - | sed 's/,/, /g')
    [ -n "$unapproved" ] || return 0
    jq -n --arg files "$unapproved" '{
        systemMessage: ("PHPMD count-dodge gate (#2025): committed without your approval while a PHPMD finding was open: " + $files),
        hookSpecificOutput: {hookEventName: "PostToolUse", additionalContext: (
            "These backend/src classes reached HEAD while a PHPMD finding was open, without the user'"'"'s approval "
            + "(created inside the commit command): " + $files + ". Tell the user and justify each one.")}}'
}

case "$mode" in
    record) runs_phpmd "$command" && record ;;
    gate) runs_commit "$command" && gate ;;
    approve) approve ;;
    settle) if runs_phpmd "$command"; then record; elif runs_commit "$command"; then settle; fi ;;
    *) echo "phpmd-gate.sh: unknown mode '$mode'" >&2; exit 1 ;;
esac
exit 0
