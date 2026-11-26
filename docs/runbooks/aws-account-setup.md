# Ранбук: безпечний старт в AWS (День 15)

Мета: акаунт на Free Plan, у якому root не використовується, Terraform працює з мінімальними правами без жодного статичного access key, а гроші під наглядом ще до першого EC2.

Кроки 1–4 і 8 робляться в консолі вручну (так задумано: це «корінь довіри», який не варто автоматизувати тим самим інструментом, якому він довіряє). Кроки 5–7 — це Terraform із цього репозиторію.

```
root (MFA, лише білінг)
  └─ IAM Identity Center
       ├─ permission set DwellioAdmin      → лише terraform/bootstrap і аварії
       └─ permission set DwellioTerraform  → щодня: terraform/envs/prod
              └─ customer managed policy dwellio-terraform-deployer  (створює bootstrap)
                     └─ може створювати ролі лише з boundary dwellio-workload-boundary
```

## 1. Реєстрація і root

- [ ] Реєстрація на **Free Plan** (не Paid): на Free Plan списань з картки немає, поки не перейдеш на Paid plan.
- [ ] Root: довгий унікальний пароль у менеджері паролів + **MFA** (passkey або TOTP). За можливості — два MFA-пристрої.
- [ ] Root **без access keys** (IAM → Security credentials: розділ Access keys порожній).
- [ ] Account → Alternate contacts: billing / security на свою пошту, щоб листи AWS про безпеку не губились.
- [ ] Billing → Billing preferences: увімкнути **Free Tier usage alerts** і PDF-інвойси на пошту.

Далі root використовуємо лише для білінгу й аварій.

## 2. IAM Identity Center (замість IAM-користувачів з ключами)

Чому не IAM-користувач з access key: ключ живе вічно, лежить у `~/.aws/credentials` відкритим текстом і рано чи пізно потрапляє в git чи лог. SSO дає тимчасові облікові дані (1–12 год) після входу з MFA.

- [ ] Регіон консолі — `eu-central-1`. IAM Identity Center → **Enable** (organization instance).
- [ ] Settings → Authentication → MFA: **Every time they sign in**, тип — authenticator apps / security keys.
- [ ] Users → створити свого користувача (не root-пошту, можна `+aws`-аліас) і підтвердити запрошення.
- [ ] Permission sets:
  - `DwellioAdmin` — predefined `AdministratorAccess`, session duration 1 год. Тільки для bootstrap.
  - `DwellioTerraform` — **поки не створюємо**: його політика з'явиться після кроку 6.
- [ ] AWS accounts → свій акаунт → Assign users → користувач + `DwellioAdmin`.
- [ ] Скопіювати **AWS access portal URL** (`https://d-xxxxxxxxxx.awsapps.com/start`).

## 3. SSO-профілі на WSL2

AWS CLI і Terraform працюють у Docker, `~/.aws` монтується в контейнер, локально нічого не ставимо.

```bash
make aws-sso-setup p=dwellio-admin
```

Майстер запитає: session name `dwellio`, start URL з кроку 2, region `eu-central-1`, scopes — за замовчуванням. Відкрий у браузері Windows показане посилання, введи код, підтверди MFA, обери акаунт і роль `DwellioAdmin`, CLI region `eu-central-1`, output `json`.

```bash
make aws-whoami p=dwellio-admin
```

Очікувано: `"Arn": "arn:aws:sts::<account>:assumed-role/AWSReservedSSO_DwellioAdmin_.../<user>"`. Слово `root` у ARN = зупинитись і перевірити кроки 1–2.

## 4. Ліміти витрат AI-провайдерів (запобіжник білінгу)

$-стеля застосунку (`AI_BUDGET_USD_*`) — це запобіжник у коді. Він не допоможе, якщо ключ утече. Тому друга лінія — у консолях провайдерів:

- [ ] **Anthropic Console:** окремий workspace `dwellio-prod` → Limits: місячний spend limit ≈ $5 і помірні rate limits. API-ключ створити саме в цьому workspace, автопоповнення кредитів вимкнути.
- [ ] **Voyage AI:** окремий ключ для проду. Перевірити в dashboard, чи є ліміт витрат. Якщо немає, тримати лише передоплачений баланс без автопоповнення.
- [ ] Ключі поки нікуди не записуємо: на Дні 18 вони підуть у SSM Parameter Store (`SecureString`, `/dwellio/prod/*`).

## 5. Bootstrap: state-бакет, бюджети, IAM

```bash
cp terraform/bootstrap/terraform.tfvars.example terraform/bootstrap/terraform.tfvars
```

Вписати `aws_account_id` (з `aws-whoami`) і `alert_email`.

```bash
make aws-login p=dwellio-admin
```

```bash
make tf-bootstrap-plan
```

Перевірити план: 1 бакет `dwellio-tfstate-<account>` (+ versioning, SSE, public access block, policy, lifecycle), 2 бюджети, 2 IAM-політики і 3 запобіжники акаунта (блок публічного доступу S3 на весь акаунт, заборона публічних snapshot EBS і AMI). **Жодних** `aws_iam_user` / `aws_iam_access_key`.

```bash
make tf-bootstrap-apply
```

- [ ] Підтвердити підписку на листи AWS Budgets, якщо прийде запит.
- [ ] Billing → Budgets: видно `dwellio-net-spend` ($1, після кредитів) і `dwellio-credit-burn` (до вирахування кредитів).

State bootstrap лежить локально в `terraform/bootstrap/terraform.tfstate` (у `.gitignore`). Секретів у ньому немає; якщо його втратити, ресурси повертаються через `terraform import`.

## 6. Permission set DwellioTerraform

- [ ] IAM Identity Center → Permission sets → Create → Custom:
  - Customer managed policies → `dwellio-terraform-deployer` (ім'я з `terraform output deployer_policy_name`; такі політики підключаються за іменем, тому вона має вже існувати в акаунті).
  - Session duration — 4 год.
- [ ] Assign: свій користувач + `DwellioTerraform`.

```bash
make aws-sso-setup p=dwellio-tf
```

Той самий start URL, роль `DwellioTerraform`.

## 7. Перевірка least privilege і remote state

```bash
cp terraform/envs/prod/backend.hcl.example terraform/envs/prod/backend.hcl
```

```bash
cp terraform/envs/prod/terraform.tfvars.example terraform/envs/prod/terraform.tfvars
```

У `backend.hcl` — бакет з `terraform output state_bucket`; у `terraform.tfvars` — account id і `backup_bucket_name = "dwellio-backups-<account>"`.

```bash
make aws-login
```

```bash
make tf-init
```

```bash
make tf-plan
```

`plan` без `apply` нічого не створює і нічого не коштує. Очікувано: ≈ 23 ресурси до створення, без `AccessDenied`. Якщо падає з `AccessDenied` — не розширювати політику навмання: подивитись дію в помилці (або CloudTrail → Event history) і додати саме її в `terraform/bootstrap/iam.tf`.

Негативні перевірки (мають **падати**):

- [ ] `instance_type = "m7i-flex.large"` у tfvars → `plan` зупиняє validation модуля. Навіть без неї IAM-Deny `DenyExpensiveInstanceTypes` не дав би запустити інстанс.
- [ ] Створення IAM-користувача під `dwellio-tf` → `AccessDenied`:

```bash
docker run --rm -it -u $(id -u):$(id -g) -e HOME=/tmp -v ~/.aws:/tmp/.aws amazon/aws-cli:2.31.0 iam create-user --user-name probe --profile dwellio-tf
```

- [ ] IAM Policy Simulator (консоль IAM → Policies → `dwellio-terraform-deployer` → Simulate), регіон `eu-central-1`. Очікувано **denied**:
  - `ec2:RunInstances` на `instance/*` з ключем `ec2:InstanceType = m7i-flex.large`;
  - `ec2:RunInstances` на `volume/*` з `ec2:VolumeType = io2` і окремо з `ec2:VolumeSize = 100`;
  - `ec2:ModifyInstanceAttribute` з `ec2:Attribute/InstanceType = m7i-flex.large` (рев'ю: ключ не підтверджено офіційним довідником — якщо симулятор каже *allowed*, Deny не працює, і це треба записати);
  - `ec2:CreateSnapshot`, `ec2:ModifySnapshotAttribute`, `ec2:CreateCapacityReservation`, `ec2:CreateVolume` — implicitly denied (їх немає в allow-list);
  - `s3:GetObject` на `dwellio-backups-<account>/pg_dump/x` — deployer дампи не читає.

`apply` — на Дні 16, разом із прод-образом.

## Межа least privilege: що IAM тут не закриває

Хто може деплоїти сервер, той фактично має доступ до його секретів (з Дня 18 — `/dwellio/*` у Parameter Store, зокрема ключ Anthropic). Шляхи, які політика deployer не перекриває за дизайном:

1. змінити `user_data` наявного інстансу або запустити новий з роллю `dwellio-prod-instance` і скриптом, що відправить секрети назовні;
2. `CreateRole` / `UpdateAssumeRolePolicy` з довірою до **чужого** акаунта. Boundary обмежує, *що* роль може, але не *кому* вона довіряє — і такий доступ переживе відкликання сесії deployer.

У IAM немає умови на вміст trust policy, тож закрити це можна лише рівнем вище: SCP в AWS Organizations (`Deny iam:CreateRole/UpdateAssumeRolePolicy`, якщо principal не з організації) або алертом CloudTrail → EventBridge на ці виклики (тиждень 5). До того — захищаємо саму сесію deployer: SSO + MFA для людини, OIDC з прив'язкою до репозиторію й гілки для CI (День 17), короткі сесії.

## 8. Підсумковий чеклист (DoD Дня 15)

- [ ] Root: MFA, без ключів, використовується лише для білінгу.
- [ ] Вхід — тільки SSO з MFA; у `~/.aws/credentials` немає довгоживучих ключів (`ls ~/.aws` — лише `config` і `sso/`).
- [ ] Бюджети: $1 фактичних витрат + 50/80% плану витрати кредитів + прогноз 100%.
- [ ] Remote state: бакет з versioning, SSE, TLS-only і `prevent_destroy`; `tf-init` проходить під `dwellio-tf`.
- [ ] Spend limits в Anthropic Console і Voyage.
- [ ] Policy Simulator з кроку 7: усі дорогі й небезпечні дії — denied.

## Як розповідати на співбесіді

- **«Як ти даєш Terraform права в AWS?»** — Не адмінські і не через ключі. Людина входить через Identity Center з MFA і отримує тимчасові облікові дані. CI з Дня 17 отримує їх через OIDC. Політика deployer — allow-list рівно під ресурси стеку: ≈ 45 дій EC2 в одному регіоні, IAM і S3 лише за префіксом `dwellio-` і лише у своєму акаунті. Поверх — Deny на дорогі типи інстансів і дисків, а запобіжники рівня акаунта (публічні S3, snapshot, AMI) deployer змінити не може.
- **«Чому permissions boundary?»** — Право `iam:CreateRole` без обмежень = ескалація до адміна: створюєш роль з `AdministratorAccess`, яка довіряє тобі, і береш її. Boundary — це стеля: deployer може створити роль лише з boundary, а сама роль ніколи не отримає більше, ніж у boundary. Зняти boundary заборонено явним Deny.
- **«Чому бюджети не в prod-стеку?»** — Бо `terraform destroy` прибрав би і алерти. Друга пастка: бюджет за замовчуванням рахує витрати *після* кредитів, тобто $0, і з кредитами ніколи не спрацює. Тому два бюджети: net (гроші з картки) і gross (швидкість витрати кредитів).
- **«Курка і яйце remote state?»** — Бакет для state створює окремий маленький стек з локальним state. Він рідко змінюється, а все інше зберігає state у цьому бакеті з нативним S3-lock (`use_lockfile`, Terraform ≥ 1.10, без DynamoDB).
- **«Чому не `ec2:*` + Deny на погане?»** — Бо deny-list завжди неповний. У першій версії політики рев'ю знайшло дірки за 10 хвилин: capacity reservations оплачуються без запущеного інстансу, io2-диск на 64k IOPS коштує тисячі доларів, а `ModifySnapshotAttribute` віддає копію диска з базою будь-кому. Ціна allow-list — `AccessDenied` на кожен новий тип ресурсу, і це правильна ціна: розширення прав стає свідомим рішенням.
- **Межа, яку варто назвати самому:** хто деплоїть, той має доступ до секретів (розділ вище). Закривається лише SCP або алертами CloudTrail, а не IAM-політикою.
- **Пастка з ARN S3:** у ARN бакета немає ID акаунта, тому `dwellio-backups-*` без умови `aws:ResourceAccount` дозволяє писати і в чужий бакет з таким префіксом.
