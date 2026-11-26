output "public_ip" {
  description = "Elastic IP сервера (A-запис домену)"
  value       = module.ec2.public_ip
}

output "instance_id" {
  description = "Для `aws ssm start-session --target <id>`"
  value       = module.ec2.instance_id
}

output "backup_bucket" {
  value = module.s3_backup.bucket_name
}
