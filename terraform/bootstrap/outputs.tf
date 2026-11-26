output "state_bucket" {
  description = "Вписати в terraform/envs/prod/backend.hcl"
  value       = aws_s3_bucket.state.id
}

output "deployer_policy_name" {
  description = "Customer managed policy для permission set DwellioTerraform в IAM Identity Center"
  value       = aws_iam_policy.deployer.name
}

output "permissions_boundary_arn" {
  description = "Boundary, без якого Terraform не може створити жодної ролі"
  value       = aws_iam_policy.workload_boundary.arn
}
