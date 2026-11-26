variable "name" {
  description = "Префікс імен ресурсів"
  type        = string
}

variable "permissions_boundary_arn" {
  description = "Стеля прав ролі (створюється в terraform/bootstrap)"
  type        = string
}

variable "backup_bucket_arn" {
  description = "ARN бакета бекапів"
  type        = string
}

variable "backup_prefix" {
  description = "Префікс, у який інстансу дозволено писати"
  type        = string
}
