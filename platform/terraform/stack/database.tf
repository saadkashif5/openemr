resource "aws_db_subnet_group" "main" {
  name       = "${var.project}-db"
  subnet_ids = aws_subnet.private[*].id
}

# Triggers (the platform-admin guard) need this on RDS, where the master user is not SUPER and binary
# logging is on. See INF-02 results in platform/PROJECT_PLAN.md.
resource "aws_db_parameter_group" "mysql84" {
  name   = "${var.project}-mysql84"
  family = "mysql8.4"

  parameter {
    name  = "log_bin_trust_function_creators"
    value = "1"
  }

  parameter {
    name  = "character_set_server"
    value = "utf8mb4"
  }
}

resource "aws_db_instance" "main" {
  identifier     = "${var.project}-db"
  engine         = "mysql"
  engine_version = "8.4"
  instance_class = var.db_instance_class

  allocated_storage = var.db_storage_gb
  # max_allocated_storage is left unset on purpose: no storage autoscaling, so the disk cannot grow.
  storage_type      = "gp3"
  storage_encrypted = true

  db_name                     = null
  username                    = "dbadmin"
  manage_master_user_password = true # generated and kept in Secrets Manager; never in state or code

  multi_az               = false # turn on before real patients (plan: later)
  db_subnet_group_name   = aws_db_subnet_group.main.name
  vpc_security_group_ids = [aws_security_group.db.id]
  publicly_accessible    = false
  parameter_group_name   = aws_db_parameter_group.mysql84.name

  backup_retention_period    = 7
  auto_minor_version_upgrade = true
  copy_tags_to_snapshot      = true

  deletion_protection       = var.protect_data
  skip_final_snapshot       = !var.protect_data
  final_snapshot_identifier = var.protect_data ? "${var.project}-db-final" : null

  tags = { Name = "${var.project}-db" }
}
