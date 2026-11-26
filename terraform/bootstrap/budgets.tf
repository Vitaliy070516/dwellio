# Бюджети рахунку живуть тут, а не в prod-стеку: `terraform destroy` prod-у не має вимикати алерти.
#
# Два різні питання — два бюджети:
#   1. "Чи списали з картки хоч долар?" — NET-вартість (кредити віднімаються, include_credit = true).
#      На Free Plan списань не буває, але після переходу на Paid plan це головний алерт.
#   2. "Як швидко я палю кредити?" — GROSS-вартість (include_credit = false). Бюджет за замовчуванням
#      рахує вартість ПІСЛЯ кредитів, тобто $0, і з кредитами ніколи б не спрацював — тиха пастка.

resource "aws_budgets_budget" "net_spend" {
  name         = "dwellio-net-spend"
  budget_type  = "COST"
  limit_amount = var.net_spend_alert_usd
  limit_unit   = "USD"
  time_unit    = "MONTHLY"

  cost_types {
    include_credit = true
    include_refund = true
  }

  notification {
    comparison_operator        = "GREATER_THAN"
    threshold                  = 100
    threshold_type             = "PERCENTAGE"
    notification_type          = "ACTUAL"
    subscriber_email_addresses = [var.alert_email]
  }
}

resource "aws_budgets_budget" "credit_burn" {
  name         = "dwellio-credit-burn"
  budget_type  = "COST"
  limit_amount = var.monthly_credit_burn_usd
  limit_unit   = "USD"
  time_unit    = "MONTHLY"

  cost_types {
    include_credit = false
    include_refund = false
  }

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

  # Прогноз > 100%: забутий увімкнений інстанс видно на початку місяця, а не наприкінці.
  notification {
    comparison_operator        = "GREATER_THAN"
    threshold                  = 100
    threshold_type             = "PERCENTAGE"
    notification_type          = "FORECASTED"
    subscriber_email_addresses = [var.alert_email]
  }
}
