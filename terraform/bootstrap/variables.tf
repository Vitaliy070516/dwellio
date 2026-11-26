variable "aws_account_id" {
  description = "ID акаунта (12 цифр): allowed_account_ids і детерміноване ім'я state-бакета"
  type        = string

  validation {
    condition     = can(regex("^[0-9]{12}$", var.aws_account_id))
    error_message = "aws_account_id — 12 цифр."
  }
}

variable "region" {
  description = "Регіон state-бакета і prod-стеку"
  type        = string
  default     = "eu-central-1"
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

variable "net_spend_alert_usd" {
  description = "Алерт на фактичне списання з картки (після кредитів) за місяць"
  type        = string
  default     = "1"
}

variable "monthly_credit_burn_usd" {
  description = "Місячний план витрати кредитів (до їх вирахування); $200 / 6 міс ≈ $33 — стеля, ціль нижче"
  type        = string
  default     = "15"
}

variable "allowed_instance_types" {
  description = "Які типи EC2 Terraform-у дозволено запускати (IAM-запобіжник поверх validation у модулі ec2)"
  type        = list(string)
  default     = ["t3.micro", "t3.small"]
}

variable "max_root_volume_gb" {
  description = "Максимальний root-диск, який Terraform-у дозволено створити (IAM Deny поверх validation модуля ec2)"
  type        = number
  default     = 30
}

variable "name_prefix" {
  description = "Префікс ресурсів prod-стеку, на який обмежено IAM-права Terraform"
  type        = string
  default     = "dwellio-prod"
}
