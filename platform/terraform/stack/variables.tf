variable "region" {
  type    = string
  default = "eu-north-1"
}

variable "project" {
  type    = string
  default = "emb-ehr"
}

variable "environment" {
  type    = string
  default = "pilot"
}

variable "alert_email" {
  type        = string
  description = "Receives budget alerts."
}

variable "monthly_budget_usd" {
  type        = number
  default     = 150
  description = "Hard ceiling: when actual spend reaches this, the stop function shuts the servers down."
}

variable "app_architecture" {
  type        = string
  default     = "arm64"
  description = "arm64 (t4g) or x86_64 (t3). Must match app_instance_type."
  validation {
    condition     = contains(["arm64", "x86_64"], var.app_architecture)
    error_message = "Use arm64 or x86_64."
  }
}

variable "app_instance_type" {
  type    = string
  default = "t4g.large"
}

variable "app_disk_gb" {
  type        = number
  default     = 50
  description = "Fixed size; documents live here because OpenEMR cannot store them in S3."
}

variable "db_instance_class" {
  type    = string
  default = "db.t4g.medium"
}

variable "db_storage_gb" {
  type        = number
  default     = 50
  description = "Fixed size. Storage autoscaling is deliberately off so the disk cannot grow past this."
}

variable "protect_data" {
  type        = bool
  default     = false
  description = "true = final snapshot on destroy and deletion protection. Leave false while only fake data exists."
}

variable "vpc_cidr" {
  type    = string
  default = "10.20.0.0/16"
}
