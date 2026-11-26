variable "name" {
  description = "Префікс імен ресурсів"
  type        = string
}

variable "instance_type" {
  description = "Тип інстансу (ADR-006)"
  type        = string
  default     = "t3.micro"

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
}

variable "swap_gb" {
  description = "Розмір swap-файлу, 0 — без swap"
  type        = number
  default     = 2
}
