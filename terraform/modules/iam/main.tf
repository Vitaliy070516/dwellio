# Роль EC2-інстансу за принципом least privilege:
#   - SSM Session Manager замість SSH-ключів;
#   - запис бекапів лише в один префікс одного бакета; читати й видаляти не може —
#     скомпрометований сервер не зітре історію бекапів (захист від ransomware).
# Access keys на машині немає: облікові дані видає IMDSv2 і вони живуть кілька годин.

data "aws_iam_policy_document" "assume_ec2" {
  statement {
    actions = ["sts:AssumeRole"]

    principals {
      type        = "Service"
      identifiers = ["ec2.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "instance" {
  name               = "${var.name}-instance"
  assume_role_policy = data.aws_iam_policy_document.assume_ec2.json
}

resource "aws_iam_role_policy_attachment" "ssm" {
  role       = aws_iam_role.instance.name
  policy_arn = "arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore"
}

data "aws_iam_policy_document" "backup_write" {
  statement {
    sid       = "PutDumpsOnly"
    actions   = ["s3:PutObject"]
    resources = ["${var.backup_bucket_arn}/${var.backup_prefix}/*"]
  }

  statement {
    sid       = "ListOwnPrefix"
    actions   = ["s3:ListBucket"]
    resources = [var.backup_bucket_arn]

    condition {
      test     = "StringLike"
      variable = "s3:prefix"
      values   = ["${var.backup_prefix}/*"]
    }
  }
}

resource "aws_iam_role_policy" "backup_write" {
  name   = "backup-write"
  role   = aws_iam_role.instance.id
  policy = data.aws_iam_policy_document.backup_write.json
}

resource "aws_iam_instance_profile" "instance" {
  name = "${var.name}-instance"
  role = aws_iam_role.instance.name
}
