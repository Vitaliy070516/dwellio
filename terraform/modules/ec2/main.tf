# Один EC2 з Docker Compose (профіль prod-light). Тип інстансу — ADR-006 (t3.small + 1 ГБ swap як страховка).

# Актуальний AMI Amazon Linux 2023 з публічного SSM-параметра: без захардкожених ami-id під регіон.
data "aws_ssm_parameter" "al2023" {
  name = "/aws/service/ami-amazon-linux-latest/al2023-ami-kernel-default-x86_64"
}

resource "aws_instance" "app" {
  ami                    = data.aws_ssm_parameter.al2023.value
  instance_type          = var.instance_type
  subnet_id              = var.subnet_id
  vpc_security_group_ids = [var.security_group_id]
  iam_instance_profile   = var.instance_profile_name

  # IMDSv2 обов'язковий: SSRF у застосунку не зможе вкрасти облікові дані ролі через IMDSv1.
  metadata_options {
    http_endpoint               = "enabled"
    http_tokens                 = "required"
    http_put_response_hop_limit = 2 # 2, бо запити йдуть із Docker-контейнерів
  }

  root_block_device {
    volume_type           = "gp3"
    volume_size           = var.root_volume_gb
    encrypted             = true
    delete_on_termination = true
  }

  # t3 у режимі unlimited може тихо нараховувати плату за CPU-кредити; standard — жорстка стеля.
  credit_specification {
    cpu_credits = "standard"
  }

  user_data = templatefile("${path.module}/user_data.sh.tftpl", {
    swap_gb = var.swap_gb
  })
  user_data_replace_on_change = true

  tags = { Name = "${var.name}-app" }

  lifecycle {
    # Новий AMI не має перестворювати сервер при кожному plan: оновлюємо свідомо.
    ignore_changes = [ami]
  }
}

resource "aws_eip" "app" {
  domain   = "vpc"
  instance = aws_instance.app.id

  tags = { Name = "${var.name}-eip" }
}
