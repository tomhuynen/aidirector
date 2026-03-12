#!/bin/bash
# Skill auto-activation hook (blueprint base)
# Reads user prompt from stdin, matches against skill keywords,
# outputs specific INSTRUCTION directives for matched skills.
#
# Projects should override this with their own version that includes
# project-specific skills (e.g., architect, project-planner).

set -e

# Read the JSON input from stdin
INPUT=$(cat)
PROMPT=$(echo "$INPUT" | grep -o '"prompt":"[^"]*"' | head -1 | sed 's/"prompt":"//;s/"$//' | tr '[:upper:]' '[:lower:]')

# If no prompt extracted, try alternate JSON parsing
if [ -z "$PROMPT" ]; then
  PROMPT=$(echo "$INPUT" | python3 -c "import sys,json; print(json.load(sys.stdin).get('prompt',''))" 2>/dev/null | tr '[:upper:]' '[:lower:]')
fi

[ -z "$PROMPT" ] && exit 0

MATCHED=""

# --- Blueprint skills (shared across all projects) ---

# pest-testing
if echo "$PROMPT" | grep -qiE '(test|spec|tdd|assert|expect|coverage|pest|phpunit|feature test|unit test)'; then
  MATCHED="${MATCHED}USE Skill(pest-testing) — Pest 3 testing patterns.\n"
fi

# tailwindcss-development
if echo "$PROMPT" | grep -qiE '(style|css|tailwind|class|dark mode|responsive|spacing|layout|flex|grid|color|typography|border|gradient|restyle|card|button|hero)'; then
  MATCHED="${MATCHED}USE Skill(tailwindcss-development) — Tailwind CSS v4 patterns.\n"
fi

# shadcn-vue-components
if echo "$PROMPT" | grep -qiE '(component|form|dialog|modal|dropdown|select|combobox|toast|sheet|popover|accordion|tabs|tooltip|ui element|input|checkbox|radio|calendar|command)'; then
  MATCHED="${MATCHED}USE Skill(shadcn-vue-components) — check shadcn-vue before writing custom UI.\n"
fi

# type-system
if echo "$PROMPT" | grep -qiE '(type|typing|schema|scramble|openapi|inertia\.d\.ts|schema\.d\.ts|page.?props|form.?request.?type|defineprops)'; then
  MATCHED="${MATCHED}USE Skill(type-system) — Scramble/OpenAPI/TypeScript type pipeline.\n"
fi

# developing-with-fortify
if echo "$PROMPT" | grep -qiE '(auth|login|register|password.?reset|2fa|two.?factor|verification|fortify|guard)'; then
  MATCHED="${MATCHED}USE Skill(developing-with-fortify) — Fortify auth patterns.\n"
fi

# datatable-rewrite
if echo "$PROMPT" | grep -qiE '(datatable|data.?table|table.?component|row.?action|bulk.?action|column|filter|usetable|useactions)'; then
  MATCHED="${MATCHED}USE Skill(datatable-rewrite) — DataTable rewrite patterns.\n"
fi

# Output matched skills as directives
if [ -n "$MATCHED" ]; then
  echo "INSTRUCTION: Before proceeding, activate these skills:"
  echo -e "$MATCHED"
  echo "Load each matched skill via the Skill tool BEFORE writing any code."
fi
