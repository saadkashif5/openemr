data "aws_ssm_parameter" "ami" {
  name = "/aws/service/ami-amazon-linux-latest/al2023-ami-kernel-default-${var.app_architecture == "arm64" ? "arm64" : "x86_64"}"
}

# Access is through SSM Session Manager, so the server has no SSH port and no key pair.
resource "aws_iam_role" "app" {
  name = "${var.project}-app"
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Action    = "sts:AssumeRole"
      Principal = { Service = "ec2.amazonaws.com" }
    }]
  })
}

resource "aws_iam_role_policy_attachment" "app_ssm" {
  role       = aws_iam_role.app.name
  policy_arn = "arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore"
}

# The app server may read the database's generated master secret and write backups, nothing else.
resource "aws_iam_role_policy" "app_access" {
  name = "app-access"
  role = aws_iam_role.app.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect   = "Allow"
        Action   = ["secretsmanager:GetSecretValue"]
        Resource = aws_db_instance.main.master_user_secret[0].secret_arn
      },
      {
        Effect   = "Allow"
        Action   = ["s3:PutObject", "s3:GetObject", "s3:ListBucket"]
        Resource = [aws_s3_bucket.backups.arn, "${aws_s3_bucket.backups.arn}/*"]
      }
    ]
  })
}

resource "aws_iam_instance_profile" "app" {
  name = "${var.project}-app"
  role = aws_iam_role.app.name
}

resource "aws_instance" "app" {
  ami                    = data.aws_ssm_parameter.ami.value
  instance_type          = var.app_instance_type
  subnet_id              = aws_subnet.public.id
  vpc_security_group_ids = [aws_security_group.app.id]
  iam_instance_profile   = aws_iam_instance_profile.app.name

  metadata_options {
    http_tokens                 = "required" # IMDSv2 only
    http_put_response_hop_limit = 2          # containers on the host can still reach it
  }

  root_block_device {
    volume_type           = "gp3"
    volume_size           = var.app_disk_gb
    encrypted             = true
    delete_on_termination = !var.protect_data
  }

  # Docker for the app image, and the MySQL client for the spike and for backups.
  user_data = <<-EOT
    #!/bin/bash
    set -eux
    dnf install -y docker mariadb1011-client-utils git
    systemctl enable --now docker
    mkdir -p /usr/local/lib/docker/cli-plugins
    curl -fsSL "https://github.com/docker/compose/releases/latest/download/docker-compose-linux-$(uname -m)" \
      -o /usr/local/lib/docker/cli-plugins/docker-compose
    chmod +x /usr/local/lib/docker/cli-plugins/docker-compose
  EOT

  tags = { Name = "${var.project}-app" }

  lifecycle {
    ignore_changes = [ami] # a newer AMI must not replace the server on the next apply
  }
}

# Stays the same across stop/start, so DNS does not change. Free while attached to a running instance.
resource "aws_eip" "app" {
  domain   = "vpc"
  instance = aws_instance.app.id
  tags     = { Name = "${var.project}-app" }
}

# Backups only (database dumps). Old objects expire so the bucket cannot grow forever.
data "aws_caller_identity" "current" {}

resource "aws_s3_bucket" "backups" {
  bucket        = "${var.project}-backups-${data.aws_caller_identity.current.account_id}"
  force_destroy = !var.protect_data
}

resource "aws_s3_bucket_public_access_block" "backups" {
  bucket                  = aws_s3_bucket.backups.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_server_side_encryption_configuration" "backups" {
  bucket = aws_s3_bucket.backups.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_lifecycle_configuration" "backups" {
  bucket = aws_s3_bucket.backups.id

  rule {
    id     = "expire-old-backups"
    status = "Enabled"
    filter {}
    expiration {
      days = 35
    }
    noncurrent_version_expiration {
      noncurrent_days = 7
    }
    abort_incomplete_multipart_upload {
      days_after_initiation = 2
    }
  }
}
