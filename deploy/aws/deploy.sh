#!/usr/bin/env bash
# Deploys wholesale-tiers to AWS as a short-lived demo:
# CloudFront -> ALB -> ECS Fargate (ARM) -> RDS MySQL 8.4,
# with secrets in SSM Parameter Store.
#
# Every resource is named from one NAME, so teardown.sh can find and delete
# all of it by that name alone. Safe to re-run: each step reuses what
# already exists, so a failed run carries on where it stopped.
#
# Run from the project root:  deploy/aws/deploy.sh [name]

source "$(dirname "$0")/common.sh" "$@"

# ---------- checks before anything is created ----------

step "Checking tools, AWS access and the code"

for tool in aws jq docker git openssl curl; do
  command -v "$tool" >/dev/null || die "$tool is not installed"
done
ok "Tools: aws, jq, docker, git, openssl, curl"

[[ -f .env && -f docker/php/Dockerfile ]] \
  || die "run this from the project root, where .env and docker/ are"

ACCOUNT_ID="$(aws sts get-caller-identity --query Account --output text)" \
  || die "the AWS CLI isn't signed in - run: aws configure"
ok "AWS account: ****${ACCOUNT_ID: -4}"

docker info >/dev/null 2>&1 || die "Docker Desktop isn't running"

# The image is built from the folder, so it has to match a commit exactly.
# Untracked files are ignored, and so is deploy/, which .dockerignore keeps
# out of the image: editing these scripts can't change what gets deployed.
[[ -z "$(git status --porcelain --untracked-files=no -- . ':(exclude)deploy')" ]] \
  || die "uncommitted changes - commit or stash them, so the image matches a commit"
GIT_SHA="$(git rev-parse --short HEAD)"
ok "Code: $(git branch --show-current) at $GIT_SHA"

# Shopify settings come from the local .env: same app, new URL.
env_value() { grep "^$1=" .env | cut -d= -f2- || true; }
SHOPIFY_API_KEY="$(env_value SHOPIFY_API_KEY)"
SHOPIFY_API_SECRET="$(env_value SHOPIFY_API_SECRET)"
SHOPIFY_SCOPES="$(env_value SHOPIFY_SCOPES)"
[[ -n "$SHOPIFY_API_KEY" && -n "$SHOPIFY_API_SECRET" && -n "$SHOPIFY_SCOPES" ]] \
  || die ".env is missing SHOPIFY_API_KEY, SHOPIFY_API_SECRET or SHOPIFY_SCOPES"
ok "Shopify settings found in .env (values not shown)"

# ---------- last chance before anything costs money ----------

cat <<EOF

About to create, in $AWS_REGION, all named "$NAME":
  security groups, parameters, an RDS MySQL 8.4 database, an ECR repository,
  an IAM role, a log group, a load balancer, a CloudFront distribution,
  and an ECS cluster and service.

Cost while it runs: about \$0.07/hour. Only teardown stops it:
  deploy/aws/teardown.sh $NAME

EOF
read -r -p "Type yes to continue: " ANSWER
[[ "$ANSWER" == "yes" ]] || die "cancelled - nothing was created"


# ---------- small helpers for the AWS steps ----------

# Finds the security group, or creates it, and prints its ID.
ensure_sg() {
  local id
  id="$(find_sg "$1")"
  if [[ -z "$id" ]]; then
    id="$(aws ec2 create-security-group --group-name "$1" --description "$2" \
      --vpc-id "$VPC_ID" --query GroupId --output text)"
  fi
  echo "$id"
}

# Adds an inbound rule. A rule that's already there counts as success.
allow_in() {
  local out
  if ! out="$(aws ec2 authorize-security-group-ingress "$@" 2>&1)"; then
    [[ "$out" == *InvalidPermission.Duplicate* ]] || die "$out"
  fi
}

# ---------- network ----------

step "Network"

VPC_ID="$(aws ec2 describe-vpcs --filters Name=is-default,Values=true \
  --query 'Vpcs[0].VpcId' --output text)"
expect "$VPC_ID" vpc- "no default VPC in $AWS_REGION"

SUBNETS_JSON="$(aws ec2 describe-subnets \
  --filters Name=vpc-id,Values="$VPC_ID" Name=default-for-az,Values=true \
  --query 'Subnets[].SubnetId' --output json | jq -c .)"
SUBNETS_CSV="$(jq -r 'join(",")' <<<"$SUBNETS_JSON")"
[[ "$(jq length <<<"$SUBNETS_JSON")" -ge 2 ]] \
  || die "the load balancer needs at least 2 default subnets"
ok "VPC $VPC_ID with $(jq length <<<"$SUBNETS_JSON") subnets"

# ---------- security groups: CloudFront -> ALB -> app -> database ----------

step "Security groups"

CF_PREFIX_LIST="$(aws ec2 describe-managed-prefix-lists \
  --filters Name=prefix-list-name,Values=com.amazonaws.global.cloudfront.origin-facing \
  --query 'PrefixLists[0].PrefixListId' --output text)"
expect "$CF_PREFIX_LIST" pl- "CloudFront's prefix list wasn't found"

ALB_SG="$(ensure_sg "${NAME}-alb" "ALB: HTTP from CloudFront only")"
expect "$ALB_SG" sg- "couldn't create security group ${NAME}-alb"
allow_in --group-id "$ALB_SG" --ip-permissions \
  "IpProtocol=tcp,FromPort=80,ToPort=80,PrefixListIds=[{PrefixListId=$CF_PREFIX_LIST}]"
ok "${NAME}-alb  $ALB_SG  port 80 from CloudFront"

APP_SG="$(ensure_sg "${NAME}-app" "App: HTTP from the ALB only")"
expect "$APP_SG" sg- "couldn't create security group ${NAME}-app"
allow_in --group-id "$APP_SG" --protocol tcp --port 80 --source-group "$ALB_SG"
ok "${NAME}-app  $APP_SG  port 80 from the ALB"

DB_SG="$(ensure_sg "${NAME}-db" "DB: MySQL from the app only")"
expect "$DB_SG" sg- "couldn't create security group ${NAME}-db"
allow_in --group-id "$DB_SG" --protocol tcp --port 3306 --source-group "$APP_SG"
ok "${NAME}-db   $DB_SG  port 3306 from the app"


# ---------- secrets in Parameter Store ----------

# Stores a parameter only if it isn't there yet. A re-run must never replace
# the database password the database was created with.
put_param_once() {
  local name="$PARAM_PATH/$1"
  if aws ssm get-parameter --name "$name" >/dev/null 2>&1; then
    ok "$name  already set"
  else
    aws ssm put-parameter --name "$name" --type "$2" --value "$3" >/dev/null \
      || die "couldn't store $name"
    ok "$name  stored"
  fi
}

step "Secrets"

put_param_once APP_KEY            SecureString "base64:$(openssl rand -base64 32)"
put_param_once DB_PASSWORD        SecureString "$(openssl rand -base64 24 | tr -d '/+=')"
put_param_once SHOPIFY_API_KEY    String       "$SHOPIFY_API_KEY"
put_param_once SHOPIFY_API_SECRET SecureString "$SHOPIFY_API_SECRET"

# The database needs the password in the next section. Read back whatever is
# stored, which on a re-run is the original, not the new one generated above.
DB_PASSWORD="$(aws ssm get-parameter --name "$PARAM_PATH/DB_PASSWORD" \
  --with-decryption --query Parameter.Value --output text)"
[[ ${#DB_PASSWORD} -ge 16 ]] || die "couldn't read the database password back"