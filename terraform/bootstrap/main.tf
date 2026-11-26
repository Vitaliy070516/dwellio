# Bootstrap рахунку (День 15): те, що має жити ДОВШЕ за prod-стек і не зникати після `terraform destroy`.
#   - S3-бакет для remote state prod-стеку (курка і яйце: state бакета state не може жити в ньому ж);
#   - бюджетні алерти рахунку (після destroy prod-у вони мають і далі стежити за грошима);
#   - IAM-політика для Terraform (least privilege) і permissions boundary для ролей, які він створює.
#
# Запускається один раз і рідко змінюється, під адмінським профілем Identity Center (MFA).
# State — локальний файл (у .gitignore): секретів у ньому немає (жодних access keys не створюємо),
# а втрату можна відновити через `terraform import`. Див. docs/runbooks/aws-account-setup.md.

provider "aws" {
  region = var.region

  # Запобіжник від apply не в той акаунт (типова помилка з кількома профілями).
  allowed_account_ids = [var.aws_account_id]

  default_tags {
    tags = {
      Project   = "dwellio"
      Stack     = "bootstrap"
      ManagedBy = "terraform"
    }
  }
}

locals {
  # Ім'я бакета детерміноване з ID акаунта: глобально унікальне без "CHANGE-ME" і без вгадування.
  state_bucket = "dwellio-tfstate-${var.aws_account_id}"
}
