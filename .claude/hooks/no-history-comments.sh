#!/usr/bin/env bash
#
# Flags wording about earlier versions of the code in a file Claude just wrote or edited, so it is
# rewritten to describe what the code does today. Exit code 2 hands the lines back to Claude.
# Also usable on its own: no-history-comments.sh <file>...

set -uo pipefail

# Anywhere in a file, prompts included: phrases that only ever describe an earlier version.
ANYWHERE='instead of the old|\blegacy\b|old (approach|pipeline|way|code|version|flow)|backwards? compat|from before (it|they|this) (said|was|were|had)|older (plans|shots|data|renders) (also|still|carry)|kept for compat|was replaced by'
# In comments only: words that in prompts and messages often describe the story, not the code.
IN_COMMENTS='previously|used to (be|do|draw|paste|say|have|work)|deprecated'
COMMENT='^[[:space:]]*(//|\*|/\*|#|<!--)'

check() {
    local file="$1"
    case "$file" in
        *.php|*.ts|*.vue|*.js|*.md) ;;
        *) return 0 ;;
    esac
    case "$file" in
        */vendor/*|*/node_modules/*|*/public/*|*/storage/*|*.d.ts|*CLAUDE.md|*AGENTS.md|*.ai/guidelines/*) return 0 ;;
    esac
    [ -f "$file" ] || return 0
    {
        grep -nEi "$ANYWHERE" "$file"
        grep -nEi "$COMMENT" "$file" | grep -Ei "$IN_COMMENTS"
    } | sort -t: -k1,1n -u | sed "s#^#${file}:#"
}

if [ "$#" -gt 0 ]; then
    found="$(for file in "$@"; do check "$file"; done)"
else
    # Run as a Claude Code hook: the edited file's path comes in on stdin as JSON.
    file="$(jq -r '.tool_input.file_path // empty' 2>/dev/null)"
    [ -n "$file" ] || exit 0
    found="$(check "$file")"
fi

if [ -n "$found" ]; then
    {
        echo "These lines talk about how the code used to be. Rewrite them to describe what the code does now, or remove them (see .ai/guidelines/code-hygiene.md):"
        echo "$found"
    } >&2
    exit 2
fi

exit 0
