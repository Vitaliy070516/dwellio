variable "name" {
  description = "Префікс імен ресурсів"
  type        = string
}

variable "instance_type" {
  description = "Тип інстансу (ADR-006: t3.small)"
  type        = string
  default     = "t3.small"

  validation {
    # Лише x86_64: AMI береться з x86_64-параметра SSM. ARM (t4g) потребує іншого AMI й multi-arch образів.
    condition     = contains(["t3.micro", "t3.small"], var.instance_type)
    error_message = "Дозволені лише дешеві типи (FinOps): t3.micro або t3.small."
  }
}

variable "subnet_id" {
  type = string
}

variable "security_group_id" {
  type = string
}

variable "instance_profile_name" {
  type = string
}

variable "root_volume_gb" {
  description = "Розмір gp3-диска (Free Plan покриває до 30 ГБ EBS)"
  type        = number
  default     = 20

  validation {
    # Та сама межа, що й IAM Deny DenyLargeVolumes у terraform/bootstrap: помилка тут, а не AccessDenied на apply.
    condition     = var.root_volume_gb >= 8 && var.root_volume_gb <= 30
    error_message = "root_volume_gb: від 8 до 30 ГБ."
  }
}

variable "swap_gb" {
  description = "Розмір swap-файлу, 0 — без swap. Страховка від OOM на піках, а не запас RAM (ADR-006)"
  type        = number
  default     = 1
}
