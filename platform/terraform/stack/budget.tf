# Spend control. AWS cannot hard-cap an account, so this does the next best thing:
#   $50, $100 and a $120 forecast  -> email
#   monthly limit (default $150)   -> email, and a function stops the EC2 and RDS instances of this project
# Billing data lags by hours, so this limits the damage; it is not an instant cut-off.

resource "aws_sns_topic" "budget" {
  name = "${var.project}-budget-limit"
}

resource "aws_sns_topic_policy" "budget" {
  arn = aws_sns_topic.budget.arn
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Principal = { Service = "budgets.amazonaws.com" }
      Action    = "SNS:Publish"
      Resource  = aws_sns_topic.budget.arn
      Condition = { StringEquals = { "aws:SourceAccount" = data.aws_caller_identity.current.account_id } }
    }]
  })
}

data "archive_file" "stop" {
  type        = "zip"
  source_file = "${path.module}/lambda/stop_servers.py"
  output_path = "${path.module}/.build/stop_servers.zip"
}

resource "aws_iam_role" "stop" {
  name = "${var.project}-stop-servers"
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Action    = "sts:AssumeRole"
      Principal = { Service = "lambda.amazonaws.com" }
    }]
  })
}

resource "aws_iam_role_policy" "stop" {
  name = "stop-servers"
  role = aws_iam_role.stop.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect   = "Allow"
        Action   = ["ec2:DescribeInstances", "rds:DescribeDBInstances", "rds:ListTagsForResource"]
        Resource = "*"
      },
      {
        Effect    = "Allow"
        Action    = ["ec2:StopInstances"]
        Resource  = "*"
        Condition = { StringEquals = { "aws:ResourceTag/Project" = var.project } }
      },
      {
        Effect    = "Allow"
        Action    = ["rds:StopDBInstance"]
        Resource  = "*"
        Condition = { StringEquals = { "aws:ResourceTag/Project" = var.project } }
      },
      {
        Effect   = "Allow"
        Action   = ["logs:CreateLogGroup", "logs:CreateLogStream", "logs:PutLogEvents"]
        Resource = "arn:aws:logs:*:*:*"
      }
    ]
  })
}

resource "aws_lambda_function" "stop" {
  function_name    = "${var.project}-stop-servers"
  role             = aws_iam_role.stop.arn
  runtime          = "python3.13"
  handler          = "stop_servers.handler"
  filename         = data.archive_file.stop.output_path
  source_code_hash = data.archive_file.stop.output_base64sha256
  timeout          = 60
  environment {
    variables = { PROJECT = var.project }
  }
}

resource "aws_sns_topic_subscription" "stop" {
  topic_arn = aws_sns_topic.budget.arn
  protocol  = "lambda"
  endpoint  = aws_lambda_function.stop.arn
}

resource "aws_lambda_permission" "sns" {
  statement_id  = "AllowSNS"
  action        = "lambda:InvokeFunction"
  function_name = aws_lambda_function.stop.function_name
  principal     = "sns.amazonaws.com"
  source_arn    = aws_sns_topic.budget.arn
}

resource "aws_budgets_budget" "monthly" {
  name         = "${var.project}-monthly"
  budget_type  = "COST"
  limit_amount = tostring(var.monthly_budget_usd)
  limit_unit   = "USD"
  time_unit    = "MONTHLY"

  dynamic "notification" {
    for_each = [50, 100]
    content {
      comparison_operator        = "GREATER_THAN"
      threshold                  = notification.value
      threshold_type             = "ABSOLUTE_VALUE"
      notification_type          = "ACTUAL"
      subscriber_email_addresses = [var.alert_email]
    }
  }

  notification {
    comparison_operator        = "GREATER_THAN"
    threshold                  = 120
    threshold_type             = "ABSOLUTE_VALUE"
    notification_type          = "FORECASTED"
    subscriber_email_addresses = [var.alert_email]
  }

  notification {
    comparison_operator        = "GREATER_THAN"
    threshold                  = var.monthly_budget_usd
    threshold_type             = "ABSOLUTE_VALUE"
    notification_type          = "ACTUAL"
    subscriber_email_addresses = [var.alert_email]
    subscriber_sns_topic_arns  = [aws_sns_topic.budget.arn]
  }

  depends_on = [aws_sns_topic_policy.budget]
}

# Free service that emails when spending is unusual, even below the budget.
resource "aws_ce_anomaly_monitor" "services" {
  name              = "${var.project}-services"
  monitor_type      = "DIMENSIONAL"
  monitor_dimension = "SERVICE"
}

resource "aws_ce_anomaly_subscription" "email" {
  name             = "${var.project}-anomalies"
  frequency        = "DAILY"
  monitor_arn_list = [aws_ce_anomaly_monitor.services.arn]

  threshold_expression {
    dimension {
      key           = "ANOMALY_TOTAL_IMPACT_ABSOLUTE"
      match_options = ["GREATER_THAN_OR_EQUAL"]
      values        = ["10"]
    }
  }

  subscriber {
    type    = "EMAIL"
    address = var.alert_email
  }
}
