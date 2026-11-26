# Права для Terraform prod-стеку (людина через IAM Identity Center сьогодні, CI через OIDC з Дня 17).
#
# Два шари:
#   1. deployer — що Terraform може робити. Точний перелік дій під ресурси стеку (allow-list), а не
#      "ec2:* мінус відоме погане": рев'ю Дня 15 за 10 хвилин знайшло в deny-list дірки і в грошах
#      (capacity reservations, io2-диски), і в даних (публічний snapshot диска з БД). Новий тип ресурсу
#      = AccessDenied на plan → дописати конкретну дію тут (під адмін-профілем).
#   2. workload_boundary — СТЕЛЯ прав для будь-якої ролі, яку створить Terraform. Без boundary
#      право "створювати ролі" = право стати адміном: створив роль з AdministratorAccess і взяв її.
#      З boundary роль ніколи не отримає більше, ніж тут, хоч би що до неї прикріпили.
#
# Чого IAM тут НЕ закриває (див. runbook, "Межа least privilege"): хто деплоїть сервер, той має доступ
# до його секретів — через user_data, новий інстанс з роллю або довіру ролі до чужого акаунта.

data "aws_partition" "current" {}

locals {
  partition     = data.aws_partition.current.partition
  account       = var.aws_account_id
  boundary_name = "dwellio-workload-boundary"
  boundary_arn  = "arn:${local.partition}:iam::${local.account}:policy/${local.boundary_name}"
  role_arns     = "arn:${local.partition}:iam::${local.account}:role/${var.name_prefix}-*"
  profile_arns  = "arn:${local.partition}:iam::${local.account}:instance-profile/${var.name_prefix}-*"
  # ARN бакета НЕ містить акаунта: без умови aws:ResourceAccount сюди підійшов би й чужий
  # "dwellio-backups-evil". Тому кожен S3-statement нижче має умову на свій акаунт.
  backup_bucket = "arn:${local.partition}:s3:::dwellio-backups-*"
  ssm_core_arn  = "arn:${local.partition}:iam::aws:policy/AmazonSSMManagedInstanceCore"

  # Рівно те, що створюють модулі network і ec2. Жодних snapshot/image/volume/peering/endpoint/
  # capacity reservation/spot — їх немає в стеку, отже й у правах.
  ec2_actions = [
    # VPC, IGW, підмережа
    "ec2:CreateVpc",
    "ec2:DeleteVpc",
    "ec2:ModifyVpcAttribute",
    "ec2:CreateInternetGateway",
    "ec2:DeleteInternetGateway",
    "ec2:AttachInternetGateway",
    "ec2:DetachInternetGateway",
    "ec2:CreateSubnet",
    "ec2:DeleteSubnet",
    "ec2:ModifySubnetAttribute",
    # Маршрути
    "ec2:CreateRouteTable",
    "ec2:DeleteRouteTable",
    "ec2:CreateRoute",
    "ec2:DeleteRoute",
    "ec2:ReplaceRoute",
    "ec2:AssociateRouteTable",
    "ec2:DisassociateRouteTable",
    "ec2:ReplaceRouteTableAssociation",
    # Security Group (aws_vpc_security_group_*_rule)
    "ec2:CreateSecurityGroup",
    "ec2:DeleteSecurityGroup",
    "ec2:AuthorizeSecurityGroupIngress",
    "ec2:AuthorizeSecurityGroupEgress",
    "ec2:RevokeSecurityGroupIngress",
    "ec2:RevokeSecurityGroupEgress",
    "ec2:ModifySecurityGroupRules",
    "ec2:UpdateSecurityGroupRuleDescriptionsIngress",
    "ec2:UpdateSecurityGroupRuleDescriptionsEgress",
    # Інстанс (root-диск створюється всередині RunInstances — окремого CreateVolume немає)
    "ec2:RunInstances",
    "ec2:TerminateInstances",
    "ec2:StartInstances",
    "ec2:StopInstances",
    "ec2:ModifyInstanceAttribute",
    "ec2:ModifyInstanceMetadataOptions",
    "ec2:ModifyInstanceCreditSpecification",
    "ec2:AssociateIamInstanceProfile",
    "ec2:ReplaceIamInstanceProfileAssociation",
    "ec2:DisassociateIamInstanceProfile",
    # Elastic IP
    "ec2:AllocateAddress",
    "ec2:ReleaseAddress",
    "ec2:AssociateAddress",
    "ec2:DisassociateAddress",
    # Теги (default_tags) і читання стану для plan
    "ec2:CreateTags",
    "ec2:DeleteTags",
    "ec2:Describe*",
  ]
}

# ---------------------------------------------------------------------------------------------------
# 1. Deployer
# ---------------------------------------------------------------------------------------------------
data "aws_iam_policy_document" "deployer" {
  # --- Remote state: лише свій ключ, лише цей бакет. Delete потрібен для lock-файлу (.tflock);
  # DeleteObjectVersion немає → при versioning видалення лише ставить delete marker, state відновлюється.
  statement {
    sid       = "StateList"
    actions   = ["s3:ListBucket"]
    resources = [aws_s3_bucket.state.arn]
  }

  statement {
    sid       = "StateObjects"
    actions   = ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"]
    resources = ["${aws_s3_bucket.state.arn}/dwellio/prod/*"]
  }

  # --- EC2 / VPC: allow-list і лише в нашому регіоні.
  statement {
    sid       = "Ec2StackResources"
    actions   = local.ec2_actions
    resources = ["*"]

    condition {
      test     = "StringEquals"
      variable = "aws:RequestedRegion"
      values   = [var.region]
    }
  }

  # FinOps на рівні IAM: навіть з помилкою в коді чи tfvars Terraform не запустить m7i.48xlarge.
  # Лише ресурс instance/*: RunInstances авторизує ще image/subnet/volume, де ключа ec2:InstanceType немає.
  statement {
    sid       = "DenyExpensiveInstanceTypes"
    effect    = "Deny"
    actions   = ["ec2:RunInstances"]
    resources = ["arn:${local.partition}:ec2:*:${local.account}:instance/*"]

    condition {
      test     = "StringNotEquals"
      variable = "ec2:InstanceType"
      values   = var.allowed_instance_types
    }
  }

  # Root-диск створюється в RunInstances: лише gp3 і не більше за ліміт (io2 на 64k IOPS — $тисячі/міс).
  # Два окремі statement-и, бо умови в одному statement об'єднуються через AND, а потрібне OR.
  statement {
    sid       = "DenyNonGp3Volumes"
    effect    = "Deny"
    actions   = ["ec2:RunInstances"]
    resources = ["arn:${local.partition}:ec2:*:${local.account}:volume/*"]

    condition {
      test     = "StringNotEquals"
      variable = "ec2:VolumeType"
      values   = ["gp3"]
    }
  }

  statement {
    sid       = "DenyLargeVolumes"
    effect    = "Deny"
    actions   = ["ec2:RunInstances"]
    resources = ["arn:${local.partition}:ec2:*:${local.account}:volume/*"]

    condition {
      test     = "NumericGreaterThan"
      variable = "ec2:VolumeSize"
      values   = [tostring(var.max_root_volume_gb)]
    }
  }

  # Зміна типу наявного інстансу (Terraform робить stop → ModifyInstanceAttribute → start).
  # ForAnyValue: ключ багатозначний і є в запиті лише тоді, коли міняють саме тип, тож зміна
  # інших атрибутів цим Deny не зачіпається. Перевірка — IAM Policy Simulator (runbook, крок 7).
  statement {
    sid       = "DenyResizeToExpensive"
    effect    = "Deny"
    actions   = ["ec2:ModifyInstanceAttribute"]
    resources = ["*"]

    condition {
      test     = "ForAnyValue:StringNotEquals"
      variable = "ec2:Attribute/InstanceType"
      values   = var.allowed_instance_types
    }
  }

  # Публічний AMI Amazon Linux 2023 (data "aws_ssm_parameter" у модулі ec2).
  statement {
    sid       = "ReadPublicAmiParameter"
    actions   = ["ssm:GetParameter", "ssm:GetParameters"]
    resources = ["arn:${local.partition}:ssm:${var.region}::parameter/aws/service/ami-amazon-linux-latest/*"]
  }

  # --- S3: лише конфігурація бакетів бекапів свого акаунта. GetObject немає — deployer не читає дампи.
  # Delete*/ListBucketVersions — для force_destroy демо-середовища.
  statement {
    sid = "BackupBucketConfig"
    actions = [
      "s3:DeleteBucket",
      "s3:ListBucket",
      "s3:ListBucketVersions",
      "s3:GetBucket*",
      "s3:GetAccelerateConfiguration",
      "s3:GetEncryptionConfiguration",
      "s3:GetLifecycleConfiguration",
      "s3:GetReplicationConfiguration",
      "s3:PutBucketPolicy",
      "s3:DeleteBucketPolicy",
      "s3:PutBucketPublicAccessBlock",
      "s3:PutBucketOwnershipControls",
      "s3:PutBucketVersioning",
      "s3:PutEncryptionConfiguration",
      "s3:PutLifecycleConfiguration",
      "s3:PutBucketTagging",
    ]
    resources = [local.backup_bucket]

    condition {
      test     = "StringEquals"
      variable = "aws:ResourceAccount"
      values   = [local.account]
    }
  }

  # CreateBucket — окремо й без aws:ResourceAccount: бакета ще немає, а створити його можна лише
  # у власному акаунті, тож умова тут нічого не захищає і лише ризикує зламати apply.
  statement {
    sid       = "BackupBucketCreate"
    actions   = ["s3:CreateBucket"]
    resources = [local.backup_bucket]
  }

  statement {
    sid       = "BackupObjectsForceDestroy"
    actions   = ["s3:DeleteObject", "s3:DeleteObjectVersion"]
    resources = ["${local.backup_bucket}/*"]

    condition {
      test     = "StringEquals"
      variable = "aws:ResourceAccount"
      values   = [local.account]
    }
  }

  # --- IAM: ролі й профілі лише з префіксом prod-стеку.
  statement {
    sid = "ManagePrefixedRoles"
    actions = [
      "iam:GetRole",
      "iam:DeleteRole",
      "iam:UpdateRole",
      "iam:UpdateAssumeRolePolicy",
      "iam:TagRole",
      "iam:UntagRole",
      "iam:ListRoleTags",
      "iam:GetRolePolicy",
      "iam:PutRolePolicy",
      "iam:DeleteRolePolicy",
      "iam:ListRolePolicies",
      "iam:ListAttachedRolePolicies",
      "iam:ListInstanceProfilesForRole",
      "iam:DetachRolePolicy",
    ]
    resources = [local.role_arns]
  }

  # Створити роль — лише з нашим boundary. Без нього CreateRole не пройде.
  statement {
    sid       = "CreateRoleOnlyWithBoundary"
    actions   = ["iam:CreateRole", "iam:PutRolePermissionsBoundary"]
    resources = [local.role_arns]

    condition {
      test     = "StringEquals"
      variable = "iam:PermissionsBoundary"
      values   = [local.boundary_arn]
    }
  }

  # Прикріплювати можна лише відомі managed-політики (зараз — лише SSM Core).
  statement {
    sid       = "AttachOnlyKnownPolicies"
    actions   = ["iam:AttachRolePolicy"]
    resources = [local.role_arns]

    condition {
      test     = "ArnEquals"
      variable = "iam:PolicyARN"
      values   = [local.ssm_core_arn]
    }
  }

  statement {
    sid = "ManagePrefixedInstanceProfiles"
    actions = [
      "iam:CreateInstanceProfile",
      "iam:DeleteInstanceProfile",
      "iam:GetInstanceProfile",
      "iam:AddRoleToInstanceProfile",
      "iam:RemoveRoleFromInstanceProfile",
      "iam:TagInstanceProfile",
      "iam:UntagInstanceProfile",
      "iam:ListInstanceProfileTags",
    ]
    resources = [local.profile_arns]
  }

  # PassRole — лише своїх ролей і лише сервісу EC2 (інакше роль можна "передати" Lambda і т.д.).
  statement {
    sid       = "PassPrefixedRolesToEc2"
    actions   = ["iam:PassRole"]
    resources = [local.role_arns]

    condition {
      test     = "StringEquals"
      variable = "iam:PassedToService"
      values   = ["ec2.amazonaws.com"]
    }
  }

  # Провайдер читає AWS-managed політику, щоб показати diff прикріплення.
  statement {
    sid       = "ReadKnownManagedPolicies"
    actions   = ["iam:GetPolicy", "iam:GetPolicyVersion"]
    resources = [local.ssm_core_arn]
  }

  # Зняти стелю з ролі = обійти весь захист. Явний Deny перемагає будь-який Allow.
  statement {
    sid       = "NeverRemoveBoundary"
    effect    = "Deny"
    actions   = ["iam:DeleteRolePermissionsBoundary"]
    resources = ["*"]
  }
}

resource "aws_iam_policy" "deployer" {
  name        = "dwellio-terraform-deployer"
  description = "Least privilege for terraform/envs/prod (Identity Center permission set and, from Day 17, the CI OIDC role)"
  policy      = data.aws_iam_policy_document.deployer.json
}

# ---------------------------------------------------------------------------------------------------
# 2. Permissions boundary для ролей, які створює deployer (зараз — роль EC2-інстансу)
# ---------------------------------------------------------------------------------------------------
data "aws_iam_policy_document" "workload_boundary" {
  # Те, що потрібно агенту SSM (AmazonSSMManagedInstanceCore), без адмінських ssm:* дій.
  statement {
    sid = "SsmAgent"
    actions = [
      "ssm:DescribeAssociation",
      "ssm:DescribeDocument",
      "ssm:GetDeployablePatchSnapshotForInstance",
      "ssm:GetDocument",
      "ssm:GetManifest",
      "ssm:ListAssociations",
      "ssm:ListInstanceAssociations",
      "ssm:PutComplianceItems",
      "ssm:PutConfigurePackageResult",
      "ssm:PutInventory",
      "ssm:UpdateAssociationStatus",
      "ssm:UpdateInstanceAssociationStatus",
      "ssm:UpdateInstanceInformation",
      "ssmmessages:CreateControlChannel",
      "ssmmessages:CreateDataChannel",
      "ssmmessages:OpenControlChannel",
      "ssmmessages:OpenDataChannel",
      "ec2messages:*",
    ]
    resources = ["*"]
  }

  # День 18: секрети застосунку в Parameter Store — лише своє дерево /dwellio/*.
  statement {
    sid       = "OwnParameters"
    actions   = ["ssm:GetParameter", "ssm:GetParameters", "ssm:GetParametersByPath"]
    resources = ["arn:${local.partition}:ssm:${var.region}:${local.account}:parameter/dwellio/*"]
  }

  # SecureString шифрується AWS-managed ключем aws/ssm; розшифровка — лише через сам SSM.
  statement {
    sid       = "DecryptViaSsmOnly"
    actions   = ["kms:Decrypt"]
    resources = ["*"]

    condition {
      test     = "StringEquals"
      variable = "kms:ViaService"
      values   = ["ssm.${var.region}.amazonaws.com"]
    }
  }

  # День 20: бекапи — лише запис і перелік, без читання й видалення (захист від ransomware).
  statement {
    sid       = "ListBackups"
    actions   = ["s3:ListBucket"]
    resources = [local.backup_bucket]

    condition {
      test     = "StringEquals"
      variable = "aws:ResourceAccount"
      values   = [local.account]
    }
  }

  statement {
    sid       = "WriteBackups"
    actions   = ["s3:PutObject"]
    resources = ["${local.backup_bucket}/*"]

    condition {
      test     = "StringEquals"
      variable = "aws:ResourceAccount"
      values   = [local.account]
    }
  }
}

resource "aws_iam_policy" "workload_boundary" {
  name        = local.boundary_name
  description = "Permissions boundary: ceiling for every role created by dwellio-terraform-deployer"
  policy      = data.aws_iam_policy_document.workload_boundary.json
}
