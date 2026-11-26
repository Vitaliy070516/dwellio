variable "aws_account_id" {
  description = "ID акаунта (12 цифр): allowed_account_ids і ARN permissions boundary з bootstrap"
  type        = string

  validation {
    condition     = can(regex("^[0-9]{12}$", var.aws_account_id))
    error_message = "aws_account_id — 12 цифр."
  }
}

variable "region" {
  description = "Регіон AWS (Франкфурт — найближчий до Києва з повним набором сервісів)"
  type        = string
  default     = "eu-central-1"
}

variable "instance_type" {
  description = "t3.small (2 ГБ) за рахунок кредитів; t3.micro лише для коротких перевірок (ADR-006)"
  type        = string
  default     = "t3.small"
}

variable "backup_bucket_name" {
  description = "Глобально унікальне ім'я бакета для pg_dump (IAM deployer дозволяє лише dwellio-backups-*)"
  type        = string

  validation {
    condition     = startswith(var.backup_bucket_name, "dwellio-backups-")
    error_message = "backup_bucket_name має починатися з dwellio-backups- (на цей префікс обмежено права Terraform)."
  }
}

variable "backup_retention_days" {
  type    = number
  default = 30
}
