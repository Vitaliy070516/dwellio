variable "region" {
  description = "Регіон AWS (Франкфурт — найближчий до Києва з повним набором сервісів)"
  type        = string
  default     = "eu-central-1"
}

variable "instance_type" {
  description = "t3.micro + swap або t3.small за рахунок кредитів (ADR-006)"
  type        = string
  default     = "t3.micro"
}

variable "backup_bucket_name" {
  description = "Глобально унікальне ім'я бакета для pg_dump"
  type        = string
}

variable "backup_retention_days" {
  type    = number
  default = 30
}

variable "monthly_budget_usd" {
  description = "Місячний ліміт витрат для AWS Budgets"
  type        = string
  default     = "10"
}

variable "alert_email" {
  description = "Куди слати бюджетні алерти"
  type        = string
  sensitive   = true

  validation {
    condition     = can(regex("^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$", var.alert_email))
    error_message = "alert_email має бути email-адресою."
  }
}
