output "app_public_ip" {
  value = aws_eip.app.public_ip
}

output "app_instance_id" {
  value = aws_instance.app.id
}

output "db_endpoint" {
  value = aws_db_instance.main.address
}

output "db_secret_arn" {
  value = aws_db_instance.main.master_user_secret[0].secret_arn
}

output "backup_bucket" {
  value = aws_s3_bucket.backups.id
}

output "connect" {
  value = "aws ssm start-session --region ${var.region} --target ${aws_instance.app.id}"
}
