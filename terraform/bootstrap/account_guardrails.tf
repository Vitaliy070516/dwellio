# Запобіжники рівня акаунта. Їх ставить адмін-профіль, а в deployer немає прав їх змінити
# (s3:PutAccountPublicAccessBlock, ec2:*SnapshotBlockPublicAccess, ec2:*ImageBlockPublicAccess) —
# тож навіть скомпрометований Terraform не зробить публічними бакет бекапів, snapshot диска чи AMI.

# Блок публічного доступу на ВСІ бакети акаунта: bucket-level block deployer міг би вимкнути,
# account-level — ні.
resource "aws_s3_account_public_access_block" "this" {
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

# Snapshot EBS = повна копія диска з PostgreSQL. Публічний шаринг заборонено в регіоні.
# Шаринг конкретному чужому акаунту закриває allow-list deployer (ModifySnapshotAttribute у ньому немає).
resource "aws_ebs_snapshot_block_public_access" "this" {
  state = "block-all-sharing"
}

# Те саме для AMI (образ інстансу теж містить диск).
resource "aws_ec2_image_block_public_access" "this" {
  state = "block-new-sharing"
}
