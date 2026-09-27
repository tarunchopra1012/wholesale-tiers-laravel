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


# ---------- database (billing starts here) ----------

step "Database - billing starts here, about \$0.02/hour"

DB_STATUS="$(aws rds describe-db-instances --db-instance-identifier "$DB_ID" \
  --query 'DBInstances[0].DBInstanceStatus' --output text 2>/dev/null || true)"
if [[ -z "$DB_STATUS" ]]; then
  # Never 8.0: since 1 Aug 2026 RDS adds an Extended Support charge to it.
  MYSQL_VERSION="$(aws rds describe-db-engine-versions --engine mysql \
    --query "DBEngineVersions[?starts_with(EngineVersion,'8.4.')].EngineVersion | [-1]" \
    --output text)"
  expect "$MYSQL_VERSION" 8.4. "RDS offers no MySQL 8.4 in $AWS_REGION"
  aws rds create-db-instance \
    --db-instance-identifier "$DB_ID" \
    --engine mysql --engine-version "$MYSQL_VERSION" \
    --engine-lifecycle-support open-source-rds-extended-support-disabled \
    --db-instance-class db.t4g.micro \
    --allocated-storage 20 --storage-type gp3 \
    --master-username laravel --master-user-password "$DB_PASSWORD" \
    --db-name "$DB_NAME" \
    --vpc-security-group-ids "$DB_SG" \
    --no-publicly-accessible --no-multi-az \
    --backup-retention-period 0 \
    --no-deletion-protection >/dev/null
  ok "database $DB_ID creating, MySQL $MYSQL_VERSION (about 10 minutes; carrying on meanwhile)"
elif [[ "$DB_STATUS" == "deleting" ]]; then
  die "database $DB_ID is still being deleted - let teardown finish, then run again"
else
  ok "database $DB_ID already exists ($DB_STATUS)"
fi

# ---------- image ----------

step "Image"

quiet aws ecr describe-repositories --repository-names "$NAME" \
  || aws ecr create-repository --repository-name "$NAME" >/dev/null
REGISTRY="${ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com"
IMAGE="${REGISTRY}/${NAME}:${GIT_SHA}"
aws ecr get-login-password | docker login --username AWS --password-stdin "$REGISTRY" >/dev/null

# The tag is the commit, so an image already pushed for it is the same image.
if quiet aws ecr describe-images --repository-name "$NAME" --image-ids imageTag="$GIT_SHA"; then
  ok "image $NAME:$GIT_SHA already in ECR"
else
  ok "building $NAME:$GIT_SHA for ARM64 (a few minutes on a cold build)"
  docker build --quiet --platform linux/arm64 --target prod \
    -t "$IMAGE" -f docker/php/Dockerfile . >/dev/null
  docker push "$IMAGE" >/dev/null
  ok "pushed $IMAGE"
fi

# ---------- IAM role for ECS ----------

step "IAM role for ECS"

if ! quiet aws iam get-role --role-name "$ROLE_NAME"; then
  aws iam create-role --role-name "$ROLE_NAME" --assume-role-policy-document \
    '{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Principal":{"Service":"ecs-tasks.amazonaws.com"},"Action":"sts:AssumeRole"}]}' \
    >/dev/null
fi
# Both are safe to repeat: attaching the same policy twice, or putting the
# same inline policy again, changes nothing.
aws iam attach-role-policy --role-name "$ROLE_NAME" \
  --policy-arn arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy
aws iam put-role-policy --role-name "$ROLE_NAME" --policy-name read-own-parameters \
  --policy-document "$(jq -nc \
    --arg r "arn:aws:ssm:${AWS_REGION}:${ACCOUNT_ID}:parameter${PARAM_PATH}/*" \
    '{Version:"2012-10-17",Statement:[{Effect:"Allow",Action:"ssm:GetParameters",Resource:$r}]}')"
EXEC_ROLE_ARN="$(aws iam get-role --role-name "$ROLE_NAME" --query Role.Arn --output text)"
expect "$EXEC_ROLE_ARN" arn:aws:iam:: "couldn't read back role $ROLE_NAME"
ok "role $ROLE_NAME: pull the image, write logs, read ${PARAM_PATH}/*"

# ---------- log group ----------

step "Log group"

LOG_FOUND="$(aws logs describe-log-groups --log-group-name-prefix "$LOG_GROUP" \
  --query "logGroups[?logGroupName=='$LOG_GROUP'].logGroupName | [0]" --output text)"
[[ "$LOG_FOUND" == "$LOG_GROUP" ]] || aws logs create-log-group --log-group-name "$LOG_GROUP"
aws logs put-retention-policy --log-group-name "$LOG_GROUP" --retention-in-days 1
ok "$LOG_GROUP, kept for 1 day"


# ---------- load balancer ----------

step "Load balancer - about \$0.035/hour with its public IPs"

ALB_ARN="$(aws elbv2 describe-load-balancers --names "$NAME" \
  --query 'LoadBalancers[0].LoadBalancerArn' --output text 2>/dev/null || true)"
if [[ "$ALB_ARN" != arn:* ]]; then
  ALB_ARN="$(aws elbv2 create-load-balancer --name "$NAME" --type application \
    --scheme internet-facing --subnets "$SUBNETS_JSON" --security-groups "$ALB_SG" \
    --query 'LoadBalancers[0].LoadBalancerArn' --output text)"
fi
expect "$ALB_ARN" arn: "couldn't create load balancer $NAME"
ALB_DNS="$(aws elbv2 describe-load-balancers --load-balancer-arns "$ALB_ARN" \
  --query 'LoadBalancers[0].DNSName' --output text)"
ok "load balancer $ALB_DNS"

TG_ARN="$(aws elbv2 describe-target-groups --names "$NAME" \
  --query 'TargetGroups[0].TargetGroupArn' --output text 2>/dev/null || true)"
if [[ "$TG_ARN" != arn:* ]]; then
  TG_ARN="$(aws elbv2 create-target-group --name "$NAME" --protocol HTTP --port 80 \
    --vpc-id "$VPC_ID" --target-type ip --health-check-path /up \
    --health-check-interval-seconds 15 --healthy-threshold-count 2 --matcher HttpCode=200 \
    --query 'TargetGroups[0].TargetGroupArn' --output text)"
fi
expect "$TG_ARN" arn: "couldn't create target group $NAME"
aws elbv2 modify-target-group-attributes --target-group-arn "$TG_ARN" \
  --attributes Key=deregistration_delay.timeout_seconds,Value=30 >/dev/null
ok "target group $NAME: GET /up every 15s"

LISTENERS="$(aws elbv2 describe-listeners --load-balancer-arn "$ALB_ARN" \
  --query 'length(Listeners)' --output text)"
if [[ "$LISTENERS" == "0" ]]; then
  aws elbv2 create-listener --load-balancer-arn "$ALB_ARN" --protocol HTTP --port 80 \
    --default-actions Type=forward,TargetGroupArn="$TG_ARN" >/dev/null
fi
ok "listener: port 80 -> target group"

# ---------- CloudFront: the https address ----------

step "CloudFront"

# teardown.sh finds the distribution by this Comment, so it must be exactly NAME.
CF_ID="$(aws cloudfront list-distributions \
  --query "DistributionList.Items[?Comment=='$NAME'].Id | [0]" --output text)"
if [[ "$CF_ID" == E* ]]; then
  # A switched-off one means a teardown stopped halfway. Reusing it would
  # leave the app unreachable.
  [[ "$(aws cloudfront get-distribution --id "$CF_ID" \
      --query 'Distribution.DistributionConfig.Enabled' --output text)" == "True" ]] \
    || die "distribution $CF_ID is switched off - finish teardown.sh first, then deploy again"
else
  CF_ID="$(aws cloudfront create-distribution --query 'Distribution.Id' --output text \
    --distribution-config "$(jq -nc --arg dns "$ALB_DNS" --arg name "$NAME" \
      --arg ref "$NAME-$(date +%s)" '{
      CallerReference: $ref,
      Comment: $name,
      Enabled: true,
      PriceClass: "PriceClass_200",
      Origins: {Quantity: 1, Items: [{
        Id: "alb",
        DomainName: $dns,
        CustomOriginConfig: {
          HTTPPort: 80, HTTPSPort: 443,
          OriginProtocolPolicy: "http-only",
          OriginSslProtocols: {Quantity: 1, Items: ["TLSv1.2"]},
          OriginReadTimeout: 30, OriginKeepaliveTimeout: 5
        }
      }]},
      DefaultCacheBehavior: {
        TargetOriginId: "alb",
        ViewerProtocolPolicy: "redirect-to-https",
        AllowedMethods: {
          Quantity: 7, Items: ["GET","HEAD","OPTIONS","PUT","POST","PATCH","DELETE"],
          CachedMethods: {Quantity: 2, Items: ["GET","HEAD"]}
        },
        CachePolicyId: "4135ea2d-6df8-44a3-9df3-4b5a84be39ad",
        OriginRequestPolicyId: "216adef6-5c7f-47e4-b989-5492eafa07d3",
        Compress: true
      }
    }')")"
fi
expect "$CF_ID" E "couldn't create the CloudFront distribution"
APP_URL="https://$(aws cloudfront get-distribution --id "$CF_ID" \
  --query 'Distribution.DomainName' --output text)"
ok "distribution $CF_ID"
ok "public address: $APP_URL"