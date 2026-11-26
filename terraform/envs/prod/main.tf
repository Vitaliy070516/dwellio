# Prod-light (тиждень 3): один EC2 з Docker Compose + бакет для бекапів + бюджетний алерт.
# `terraform destroy` після демо — кредити Free Plan мають прожити всі 6 місяців.

provider "aws" {
  region = var.region

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

  name              = local.name
  backup_bucket_arn = module.s3_backup.bucket_arn
  backup_prefix     = module.s3_backup.prefix
}

module "ec2" {
  source = "../../modules/ec2"

  name                  = local.name
  instance_type         = var.instance_type
  subnet_id             = module.network.subnet_id
  security_group_id     = module.network.security_group_id
  instance_profile_name = module.iam.instance_profile_name
}

# Друга лінія FinOps-захисту поруч із ручним алертом на $1 із чеклиста:
# фактичні витрати > 50% / 80% місячного ліміту і прогноз > 100% → лист.
resource "aws_budgets_budget" "monthly" {
  name         = "${local.name}-monthly"
  budget_type  = "COST"
  limit_amount = var.monthly_budget_usd
  limit_unit   = "USD"
  time_unit    = "MONTHLY"

  dynamic "notification" {
    for_each = [50, 80]

    content {
      comparison_operator        = "GREATER_THAN"
      threshold                  = notification.value
      threshold_type             = "PERCENTAGE"
      notification_type          = "ACTUAL"
      subscriber_email_addresses = [var.alert_email]
    }
  }

  notification {
    comparison_operator        = "GREATER_THAN"
    threshold                  = 100
    threshold_type             = "PERCENTAGE"
    notification_type          = "FORECASTED"
    subscriber_email_addresses = [var.alert_email]
  }
}
