# Prod-light (тиждень 3): один EC2 з Docker Compose + бакет для бекапів.
# Передумова — terraform/bootstrap (state-бакет, бюджети, IAM deployer + boundary), див. docs/runbooks/aws-account-setup.md.
# `terraform destroy` після демо — кредити Free Plan мають прожити всі 6 місяців.

provider "aws" {
  region = var.region

  # Запобіжник від apply не в той акаунт (кілька профілів SSO на одній машині).
  allowed_account_ids = [var.aws_account_id]

  default_tags {
    tags = {
      Project     = "dwellio"
      Environment = "prod"
      ManagedBy   = "terraform"
    }
  }
}

locals {
  name = "dwellio-prod"
  # Створюється в bootstrap; deployer-політика не дозволить CreateRole без нього.
  permissions_boundary_arn = "arn:aws:iam::${var.aws_account_id}:policy/dwellio-workload-boundary"
}

module "network" {
  source = "../../modules/network"

  name              = local.name
  availability_zone = "${var.region}a"
}

module "s3_backup" {
  source = "../../modules/s3_backup"

  bucket_name    = var.backup_bucket_name
  retention_days = var.backup_retention_days
  force_destroy  = true # демо-середовище: destroy наприкінці тижня 6
}

module "iam" {
  source = "../../modules/iam"

  name                     = local.name
  permissions_boundary_arn = local.permissions_boundary_arn
  backup_bucket_arn        = module.s3_backup.bucket_arn
  backup_prefix            = module.s3_backup.prefix
}

module "ec2" {
  source = "../../modules/ec2"

  name                  = local.name
  instance_type         = var.instance_type
  subnet_id             = module.network.subnet_id
  security_group_id     = module.network.security_group_id
  instance_profile_name = module.iam.instance_profile_name
}

# Бюджетні алерти — у terraform/bootstrap: вони мають пережити `terraform destroy` цього стеку.
