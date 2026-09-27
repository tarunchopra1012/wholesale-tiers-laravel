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


# Runs a command with its output thrown away. Used as a yes/no check:
# "does this exist?"
quiet() { "$@" >/dev/null 2>&1; }

# ---------- the app: ECS service, cluster, task definitions ----------

step "ECS service, cluster and task definitions"

SERVICE_STATUS="$(aws ecs describe-services --cluster "$NAME" --services "$NAME" \
  --query 'services[0].status' --output text 2>/dev/null || true)"
if [[ "$SERVICE_STATUS" == "ACTIVE" ]]; then
  aws ecs update-service --cluster "$NAME" --service "$NAME" --desired-count 0 >/dev/null
  aws ecs delete-service --cluster "$NAME" --service "$NAME" --force >/dev/null
fi
if [[ "$SERVICE_STATUS" == "ACTIVE" || "$SERVICE_STATUS" == "DRAINING" ]]; then
  aws ecs wait services-inactive --cluster "$NAME" --services "$NAME"
  ok "service $NAME deleted"
else
  ok "service: none"
fi

CLUSTER_STATUS="$(aws ecs describe-clusters --clusters "$NAME" \
  --query 'clusters[0].status' --output text 2>/dev/null || true)"
if [[ "$CLUSTER_STATUS" == "ACTIVE" ]]; then
  aws ecs delete-cluster --cluster "$NAME" >/dev/null
  ok "cluster $NAME deleted"
else
  ok "cluster: none"
fi

# --family-prefix matches any family that starts with NAME, so a deployment
# called "wholesale-tiers-2" would match too. Only deregister exact matches.
for TD in $(aws ecs list-task-definitions --family-prefix "$NAME" --status ACTIVE \
    --query 'taskDefinitionArns' --output text); do
  if [[ "$TD" == *":task-definition/$NAME:"* ]]; then
    aws ecs deregister-task-definition --task-definition "$TD" >/dev/null
    ok "task definition ${TD##*/} deregistered"
  fi
done

# ---------- CloudFront: switch off now, delete at the very end ----------

step "CloudFront"

# deploy.sh sets the distribution's Comment to NAME; that's how it's found.
CF_ID="$(aws cloudfront list-distributions \
  --query "DistributionList.Items[?Comment=='$NAME'].Id | [0]" --output text)"
if [[ "$CF_ID" == E* ]]; then
  CF_CONFIG="$(aws cloudfront get-distribution-config --id "$CF_ID")"
  if [[ "$(jq -r .DistributionConfig.Enabled <<<"$CF_CONFIG")" == "true" ]]; then
    aws cloudfront update-distribution --id "$CF_ID" \
      --if-match "$(jq -r .ETag <<<"$CF_CONFIG")" \
      --distribution-config "$(jq -c '.DistributionConfig.Enabled = false | .DistributionConfig' <<<"$CF_CONFIG")" \
      >/dev/null
    ok "distribution $CF_ID switching off (deleted at the end)"
  else
    ok "distribution $CF_ID already off (deleted at the end)"
  fi
else
  CF_ID=""
  ok "distribution: none"
fi

# ---------- load balancer and target group ----------

step "Load balancer"

ALB_ARN="$(aws elbv2 describe-load-balancers --names "$NAME" \
  --query 'LoadBalancers[0].LoadBalancerArn' --output text 2>/dev/null || true)"
if [[ "$ALB_ARN" == arn:* ]]; then
  aws elbv2 delete-load-balancer --load-balancer-arn "$ALB_ARN"
  aws elbv2 wait load-balancers-deleted --load-balancer-arns "$ALB_ARN"
  ok "load balancer $NAME deleted"
else
  ok "load balancer: none"
fi

TG_ARN="$(aws elbv2 describe-target-groups --names "$NAME" \
  --query 'TargetGroups[0].TargetGroupArn' --output text 2>/dev/null || true)"
if [[ "$TG_ARN" == arn:* ]]; then
  aws elbv2 delete-target-group --target-group-arn "$TG_ARN"
  ok "target group $NAME deleted"
else
  ok "target group: none"
fi

# ---------- database: start deleting now, wait for it further down ----------

step "Database"

DB_STATUS="$(aws rds describe-db-instances --db-instance-identifier "$DB_ID" \
  --query 'DBInstances[0].DBInstanceStatus' --output text 2>/dev/null || true)"
case "$DB_STATUS" in
  "")
    ok "database: none" ;;
  deleting)
    ok "database $DB_ID already deleting" ;;
  *)
    # RDS won't delete an instance that's mid-change, e.g. still being created.
    [[ "$DB_STATUS" == "available" ]] \
      || aws rds wait db-instance-available --db-instance-identifier "$DB_ID"
    aws rds delete-db-instance --db-instance-identifier "$DB_ID" \
      --skip-final-snapshot --delete-automated-backups >/dev/null
    ok "database $DB_ID deleting (waited for below)" ;;
esac


# ---------- image store, logs, secrets ----------

step "Image store, logs and secrets"

if quiet aws ecr describe-repositories --repository-names "$NAME"; then
  aws ecr delete-repository --repository-name "$NAME" --force >/dev/null
  ok "image store $NAME deleted, with its images"
else
  ok "image store: none"
fi

# The prefix search would also return /ecs/NAME-2; keep the exact match only.
LOG_FOUND="$(aws logs describe-log-groups --log-group-name-prefix "$LOG_GROUP" \
  --query "logGroups[?logGroupName=='$LOG_GROUP'].logGroupName | [0]" --output text)"
if [[ "$LOG_FOUND" == "$LOG_GROUP" ]]; then
  aws logs delete-log-group --log-group-name "$LOG_GROUP"
  ok "log group $LOG_GROUP deleted"
else
  ok "log group: none"
fi

# get-parameters-by-path only returns what's under /NAME/, never /NAME-2/.
PARAMS="$(aws ssm get-parameters-by-path --path "$PARAM_PATH" \
  --query 'Parameters[].Name' --output text)"
if [[ -n "$PARAMS" && "$PARAMS" != "None" ]]; then
  # Unquoted on purpose: each name becomes its own word.
  aws ssm delete-parameters --names $PARAMS >/dev/null
  ok "parameters deleted:"
  for P in $PARAMS; do ok "  $P"; done
else
  ok "parameters: none"
fi

# ---------- IAM role ----------

step "IAM role"

if quiet aws iam get-role --role-name "$ROLE_NAME"; then
  # A role can't be deleted while it still has policies, so remove them first.
  for POLICY in $(aws iam list-role-policies --role-name "$ROLE_NAME" \
      --query 'PolicyNames' --output text); do
    aws iam delete-role-policy --role-name "$ROLE_NAME" --policy-name "$POLICY"
  done
  for POLICY_ARN in $(aws iam list-attached-role-policies --role-name "$ROLE_NAME" \
      --query 'AttachedPolicies[].PolicyArn' --output text); do
    aws iam detach-role-policy --role-name "$ROLE_NAME" --policy-arn "$POLICY_ARN"
  done
  aws iam delete-role --role-name "$ROLE_NAME"
  ok "role $ROLE_NAME deleted"
else
  ok "role: none"
fi

# ---------- wait for the database started in the Database step ----------

step "Waiting for the database to finish deleting"

if [[ -n "$DB_STATUS" ]]; then
  aws rds wait db-instance-deleted --db-instance-identifier "$DB_ID"
  ok "database $DB_ID deleted"
else
  ok "nothing to wait for"
fi

# ---------- security groups ----------

step "Security groups"

# A just-deleted database, load balancer or container can hold on to a
# network connection for a few minutes, and AWS refuses to delete a group
# while one remains. So: retry every 30 seconds, for up to 10 minutes.
delete_sg() {
  local id tries=0
  id="$(find_sg "$1")"
  if [[ -z "$id" ]]; then
    ok "$1: none"
    return 0
  fi
  until aws ec2 delete-security-group --group-id "$id" >/dev/null 2>&1; do
    tries=$((tries + 1))
    [[ $tries -lt 20 ]] \
      || die "$1 ($id) was still in use after 10 minutes - run teardown again later"
    ok "$1 still in use, retrying in 30s"
    sleep 30
  done
  ok "$1  $id deleted"
}

# Each group is named in the rule of the one after it: db, then app, then alb.
delete_sg "${NAME}-db"
delete_sg "${NAME}-app"
delete_sg "${NAME}-alb"

# ---------- CloudFront, switched off at the start, deleted now ----------

step "CloudFront, final delete"

if [[ -n "$CF_ID" ]]; then
  ok "waiting for $CF_ID to finish switching off (often 5-15 minutes)"
  aws cloudfront wait distribution-deployed --id "$CF_ID"
  aws cloudfront delete-distribution --id "$CF_ID" \
    --if-match "$(aws cloudfront get-distribution --id "$CF_ID" --query ETag --output text)"
  ok "distribution $CF_ID deleted"
else
  ok "distribution: none"
fi


# ---------- prove nothing is left ----------

step "Checking nothing named $NAME is left"

# Each check adds a line to LEFT if the thing still exists.
LEFT=""
left() { LEFT="${LEFT}"$'\n'"    - $1"; }

[[ "$(aws ecs describe-clusters --clusters "$NAME" --query 'clusters[0].status' \
    --output text 2>/dev/null || true)" != "ACTIVE" ]] || left "ECS cluster $NAME"
[[ "$(aws cloudfront list-distributions \
    --query "DistributionList.Items[?Comment=='$NAME'].Id | [0]" --output text)" != E* ]] \
  || left "CloudFront distribution with comment $NAME"
quiet aws elbv2 describe-load-balancers --names "$NAME" && left "load balancer $NAME"
quiet aws elbv2 describe-target-groups --names "$NAME" && left "target group $NAME"
quiet aws rds describe-db-instances --db-instance-identifier "$DB_ID" && left "database $DB_ID"
quiet aws ecr describe-repositories --repository-names "$NAME" && left "image store $NAME"
[[ "$(aws logs describe-log-groups --log-group-name-prefix "$LOG_GROUP" \
    --query "logGroups[?logGroupName=='$LOG_GROUP'].logGroupName | [0]" --output text)" \
    != "$LOG_GROUP" ]] || left "log group $LOG_GROUP"
[[ -z "$(aws ssm get-parameters-by-path --path "$PARAM_PATH" \
    --query 'Parameters[].Name' --output text | grep -v '^None$' || true)" ]] \
  || left "parameters under $PARAM_PATH"
quiet aws iam get-role --role-name "$ROLE_NAME" && left "IAM role $ROLE_NAME"
for SG in "${NAME}-alb" "${NAME}-app" "${NAME}-db"; do
  [[ -z "$(find_sg "$SG")" ]] || left "security group $SG"
done

if [[ -z "$LEFT" ]]; then
  ok "nothing left: $NAME is fully deleted"
  ok "If Shopify pointed at this deployment, release a Dev Dashboard version"
  ok "with your tunnel URL again."
else
  die "still there:$LEFT
Run teardown again, or remove these in the AWS console."
fi