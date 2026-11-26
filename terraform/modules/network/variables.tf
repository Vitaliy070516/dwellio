variable "name" {
  description = "Префікс імен ресурсів"
  type        = string
}

variable "vpc_cidr" {
  description = "CIDR VPC"
  type        = string
  default     = "10.20.0.0/16"

  validation {
    condition     = can(cidrhost(var.vpc_cidr, 0))
    error_message = "vpc_cidr має бути коректним CIDR, напр. 10.20.0.0/16."
  }
}

variable "availability_zone" {
  description = "AZ єдиної підмережі"
  type        = string
}
