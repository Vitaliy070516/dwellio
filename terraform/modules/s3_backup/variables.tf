variable "bucket_name" {
  description = "Глобально унікальне ім'я бакета бекапів"
  type        = string

  validation {
    condition     = can(regex("^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$", var.bucket_name))
    error_message = "Ім'я S3-бакета: 3–63 символи, малі літери, цифри, '.', '-'."
  }
}

variable "prefix" {
  description = "Префікс об'єктів pg_dump (на нього ж обмежено IAM-права інстансу)"
  type        = string
  default     = "pg_dump"
}

variable "retention_days" {
  description = "Скільки днів зберігати дампи"
  type        = number
  default     = 30

  validation {
    condition     = var.retention_days >= 1 && var.retention_days <= 365
    error_message = "retention_days: від 1 до 365."
  }
}

variable "force_destroy" {
  description = "Дозволити destroy непорожнього бакета (лише для демо)"
  type        = bool
  default     = false
}
