# Мінімальна мережа: одна публічна підмережа без NAT Gateway (NAT ≈ $32/міс — дорожче за сам EC2).
# Для prod-light цього досить: БД і Redis живуть на тому ж EC2 і назовні не відкриті.

resource "aws_vpc" "this" {
  cidr_block           = var.vpc_cidr
  enable_dns_hostnames = true
  enable_dns_support   = true

  tags = { Name = "${var.name}-vpc" }
}

resource "aws_internet_gateway" "this" {
  vpc_id = aws_vpc.this.id

  tags = { Name = "${var.name}-igw" }
}

resource "aws_subnet" "public" {
  vpc_id            = aws_vpc.this.id
  cidr_block        = cidrsubnet(var.vpc_cidr, 8, 1)
  availability_zone = var.availability_zone
  # Публічна IP видається через Elastic IP у модулі ec2, а не автоматично.
  map_public_ip_on_launch = false

  tags = { Name = "${var.name}-public" }
}

resource "aws_route_table" "public" {
  vpc_id = aws_vpc.this.id

  route {
    cidr_block = "0.0.0.0/0"
    gateway_id = aws_internet_gateway.this.id
  }

  tags = { Name = "${var.name}-public" }
}

resource "aws_route_table_association" "public" {
  subnet_id      = aws_subnet.public.id
  route_table_id = aws_route_table.public.id
}

# Назовні — лише HTTP/HTTPS (Caddy/Nginx з TLS; WebSockets Reverb ідуть через той самий 443).
# SSH (22) НЕ відкриваємо: доступ до машини — через SSM Session Manager (IAM + аудит у CloudTrail).
resource "aws_security_group" "app" {
  name        = "${var.name}-app"
  description = "Public HTTP/HTTPS only; admin access via SSM, no SSH"
  vpc_id      = aws_vpc.this.id

  tags = { Name = "${var.name}-app" }
}

resource "aws_vpc_security_group_ingress_rule" "http" {
  security_group_id = aws_security_group.app.id
  description       = "HTTP (redirect to HTTPS, ACME challenge)"
  cidr_ipv4         = "0.0.0.0/0"
  ip_protocol       = "tcp"
  from_port         = 80
  to_port           = 80
}

resource "aws_vpc_security_group_ingress_rule" "https" {
  security_group_id = aws_security_group.app.id
  description       = "HTTPS and WSS (Reverb behind the reverse proxy)"
  cidr_ipv4         = "0.0.0.0/0"
  ip_protocol       = "tcp"
  from_port         = 443
  to_port           = 443
}

resource "aws_vpc_security_group_egress_rule" "all" {
  security_group_id = aws_security_group.app.id
  description       = "Outbound: LLM APIs, package mirrors, S3, SSM"
  cidr_ipv4         = "0.0.0.0/0"
  ip_protocol       = "-1"
}
