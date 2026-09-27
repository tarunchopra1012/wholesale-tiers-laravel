# Shared by deploy.sh and teardown.sh: the helpers, the name and region
# prompts, and every resource name derived from NAME. One copy of the names
# means teardown can never look for something deploy named differently,
# which would leave it running, and billing.

set -euo pipefail
export AWS_PAGER=""

# ---------- helpers ----------

CURRENT_STEP="starting"

step() { CURRENT_STEP="$1"; printf '\n\033[1m==> %s\033[0m\n' "$1"; }
ok()   { printf '    %s\n' "$1"; }
die()  { printf '\n\033[31mStopped: %s\033[0m\n' "$1" >&2; exit 1; }

# Any failing command stops the script and says which step it was in.
trap 'die "a command failed during: $CURRENT_STEP"' ERR

# Stops unless VALUE starts with PREFIX. Used after every lookup that should
# print an ID, because a failure inside $( ... ) doesn't stop the script by
# itself in the bash that macOS ships.
expect() { [[ "$1" == "$2"* ]] || die "$3"; }

# Prints the ID of the security group with this name, or nothing.
find_sg() {
  aws ec2 describe-security-groups \
    --filters Name=vpc-id,Values="$VPC_ID" Name=group-name,Values="$1" \
    --query 'SecurityGroups[0].GroupId' --output text | grep -v '^None$' || true
}


# Runs a command with its output thrown away. Used as a yes/no check:
# "does this exist?"
quiet() { "$@" >/dev/null 2>&1; }

# ---------- name and region ----------

NAME="${1:-}"
if [[ -z "$NAME" ]]; then
  read -r -p "Name for this deployment [wholesale-tiers]: " NAME
  NAME="${NAME:-wholesale-tiers}"
fi

# Lowercase letters, digits and single hyphens, 4-28 characters. The load
# balancer and target group cap names at 32, and RDS refuses "--" or a
# trailing hyphen.
[[ "$NAME" =~ ^[a-z][a-z0-9-]{2,26}[a-z0-9]$ ]] \
  || die "name must be 4-28 characters: lowercase letters, digits, hyphens, starting with a letter"
[[ "$NAME" != *--* ]] || die "name can't contain two hyphens in a row"

read -r -p "AWS region [ap-south-1]: " REGION
export AWS_REGION="${REGION:-ap-south-1}"

# Every resource name comes from NAME.
DB_ID="${NAME}-db"
DB_NAME="${NAME//-/_}"              # MySQL database names can't hold hyphens
PARAM_PATH="/${NAME}"
LOG_GROUP="/ecs/${NAME}"
ROLE_NAME="${NAME}-task-execution"

ok "Deployment: $NAME in $AWS_REGION"