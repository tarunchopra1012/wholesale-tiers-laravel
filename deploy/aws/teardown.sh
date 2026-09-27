#!/usr/bin/env bash
# Deletes everything deploy.sh created for one NAME, in dependency order.
# Finds each resource by its name, skips anything already gone, and ends by
# checking that nothing is left. Safe to re-run after a partial failure.
#
# Run from the project root:  deploy/aws/teardown.sh [name]

source "$(dirname "$0")/common.sh" "$@"

cat <<EOF

This deletes everything named "$NAME" in $AWS_REGION, including the
database and all its data. It can't be undone.

EOF
read -r -p "Type the name ($NAME) to confirm: " ANSWER
[[ "$ANSWER" == "$NAME" ]] || die "cancelled - nothing was deleted"

VPC_ID="$(aws ec2 describe-vpcs --filters Name=is-default,Values=true \
  --query 'Vpcs[0].VpcId' --output text)"
expect "$VPC_ID" vpc- "no default VPC in $AWS_REGION"