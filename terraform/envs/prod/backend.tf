# Remote state у S3 (чеклист AWS, пункт 4). Partial configuration: ім'я бакета й регіон
# не комітимо, а передаємо при init (бакет state створюється вручну один раз, до першого apply):
#
#   terraform init -backend-config=backend.hcl
#
# use_lockfile — нативне блокування через S3 (Terraform >= 1.10), DynamoDB-таблиця більше не потрібна.
# Для `terraform validate` без акаунта: `terraform init -backend=false` (див. make tf-validate).
terraform {
  backend "s3" {
    key          = "dwellio/prod/terraform.tfstate"
    encrypt      = true
    use_lockfile = true
  }
}
