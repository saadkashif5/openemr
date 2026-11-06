# Terraform (INF-02)

Region `eu-north-1`. Two folders:

- `bootstrap/` run once, local state. Creates the S3 bucket that stores the state of `stack/`.
- `stack/` the pilot: network, app server (EC2), database (RDS MySQL 8.4), backup bucket, budget with a
  stop function. No NAT gateway, no SSH port (use SSM), no storage autoscaling.

## Sign in

```
aws configure sso --profile emb      # or: aws configure --profile emb
aws sts get-caller-identity --profile emb
$env:AWS_PROFILE = "emb"             # PowerShell; use `export AWS_PROFILE=emb` in bash
```

## First time

```
cd bootstrap
terraform init
terraform apply                      # note the state_bucket output

cd ../stack
cp example.tfvars terraform.tfvars   # set alert_email
terraform init -backend-config="bucket=<state_bucket>"
terraform plan
terraform apply
```

After `apply`, confirm the budget email subscription (AWS sends a confirmation link) and read the
`connect` output for the SSM command.

## Spike on x86 instead of ARM

Set `app_architecture = "x86_64"` and `app_instance_type = "t3.large"` in `terraform.tfvars`, then apply.
The server is replaced; the database stays.

## Stop spending

```
terraform destroy                    # removes everything in stack/
```

Or stop (not destroy) the two instances in the console and start them when needed. A stopped RDS
instance is started again by AWS after 7 days. Disks and database storage keep billing at the storage rate.

## Spend limits in place

| Control | Value |
|---|---|
| Budget emails | $50, $100 actual; $120 forecast; limit actual (default $150) |
| At the limit | `stop_servers` function stops this project's EC2 and RDS instances |
| Database storage | fixed 50 GB, autoscaling off |
| Server disk | fixed 50 GB |
| Backup bucket | objects expire after 35 days; private |
| Unusual spend | Cost Anomaly Detection emails at $10 impact |

Billing data lags by hours, and AWS cannot hard-cap an account. This limits the damage; it is not an instant stop.
Data transfer out is the one cost with no ceiling, so look at it first if a bill is higher than expected.
