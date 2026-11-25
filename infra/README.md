# Infraestructura AWS (Persona B)

Región: **us-east-2 (Ohio)**, fijada por AWS al proyecto. Cuenta: **plan gratuito** (Free plan) de la experiencia nueva de AWS, con Builder ID y sin "advanced features".

Todo lo que se pueda se crea con CloudFormation, por tres razones:

1. Queda como código, revisable y versionado.
2. Sirve de evidencia: la plantilla dice exactamente qué se configuró.
3. El retiro de recursos (#33) es borrar los stacks en orden inverso.

## Orden de los stacks

| Orden | Stack | Plantilla | Issue |
|---|---|---|---|
| 1 | `consultorio-red` | `cloudformation/01-red.yaml` | #13 |
| 2 | `consultorio-ec2` | `cloudformation/02-ec2.yaml` | #14 |
| 3 | `consultorio-datos` | `cloudformation/03-datos.yaml` | #15, #16, #18 |
| 4 | `consultorio-cdn` | `cloudformation/04-cdn.yaml` | #17 |

La EC2 va antes que los datos porque el stack de datos agrega permisos de DynamoDB y S3 al rol de la instancia, y el grupo de seguridad de RDS ya acepta a la EC2 desde el stack de red.

Cada stack exporta sus salidas (IDs de VPC, subredes, grupos de seguridad) y el siguiente las importa con `Fn::ImportValue`.

## Requisitos en tu máquina

1. AWS CLI v2:

   ```powershell
   winget install Amazon.AWSCLI
   ```

2. Iniciar sesión con tu Builder ID (ver `docs/infra/02-cuenta-aws.md`; en esta cuenta no hay usuarios IAM para personas). `aws login` usa tu sesión de consola y da credenciales temporales, sin llaves de acceso guardadas en disco:

   ```powershell
   aws login --profile consultorio --region us-east-2
   ```

3. Verificar la identidad:

   ```powershell
   aws sts get-caller-identity --profile consultorio
   ```

## Stack 1: red (#13)

Primero obtén el ID de la lista de prefijos de CloudFront para us-east-2:

```powershell
aws ec2 describe-managed-prefix-lists --region us-east-2 --profile consultorio --filters Name=prefix-list-name,Values=com.amazonaws.global.cloudfront.origin-facing --query "PrefixLists[0].PrefixListId" --output text
```

Después crea el stack con ese ID. No pongas `AdminCidr`: así no se abre el puerto 22 y la administración se hace por SSM Session Manager.

```powershell
aws cloudformation deploy --region us-east-2 --profile consultorio --stack-name consultorio-red --template-file infra/cloudformation/01-red.yaml --parameter-overrides CloudFrontPrefixListId=pl-XXXXXXXX --tags Proyecto=consultorio-saas
```

Solo si necesitaras SSH, agrega `AdminCidr=<tu IP>/32` (tu IP sale de `curl ifconfig.me`).

Para comprobar las salidas:

```powershell
aws cloudformation describe-stacks --region us-east-2 --profile consultorio --stack-name consultorio-red --query "Stacks[0].Outputs" --output table
```

## Stack 2: EC2 (#14)

```powershell
aws cloudformation deploy --region us-east-2 --profile consultorio --stack-name consultorio-ec2 --template-file infra/cloudformation/02-ec2.yaml --capabilities CAPABILITY_NAMED_IAM --tags Proyecto=consultorio-saas
```

Verificación y evidencias: `docs/infra/14-ec2.md`.

## Stack 3: datos (#15, #16, #18)

```powershell
aws cloudformation deploy --region us-east-2 --profile consultorio --stack-name consultorio-datos --template-file infra/cloudformation/03-datos.yaml --capabilities CAPABILITY_NAMED_IAM --tags Proyecto=consultorio-saas
```

Después se crean las cuentas de la base con `db/roles.sql` (rama `main`). Pasos, pruebas y evidencias: `docs/infra/15-16-18-datos.md`.

## Stack 4: frontend y CDN (#17)

```powershell
aws cloudformation deploy --region us-east-2 --profile consultorio --stack-name consultorio-cdn --template-file infra/cloudformation/04-cdn.yaml --tags Proyecto=consultorio-saas
```

Subida del frontend, pruebas y evidencias: `docs/infra/17-cdn.md`.

## Costos que evitamos a propósito

- **Sin NAT Gateway**, que cuesta unos 32 USD al mes aunque no se use. La RDS en subred privada no necesita salir a Internet.
- **Una sola IPv4 pública**, la de la EC2. Cada IPv4 pública cuesta cerca de 3.6 USD al mes.
- Instancias de tamaño `micro`, dentro del plan gratuito.

## Retiro (#33)

Se borra en orden inverso (`04` → `01`):

```powershell
aws cloudformation delete-stack --region us-east-2 --profile consultorio --stack-name consultorio-cdn
```

(Vaciar antes el bucket del frontend.)

```powershell
aws cloudformation delete-stack --region us-east-2 --profile consultorio --stack-name consultorio-datos
```

(Vaciar antes el bucket de respaldos.)

```powershell
aws cloudformation delete-stack --region us-east-2 --profile consultorio --stack-name consultorio-ec2
```

```powershell
aws cloudformation delete-stack --region us-east-2 --profile consultorio --stack-name consultorio-red
```

También hay que borrar a mano lo que no crea CloudFormation: los snapshots manuales de RDS, la instancia temporal de restauración y los recursos de Elastic Beanstalk (su grupo de seguridad y el bucket `elasticbeanstalk-us-east-2-<cuenta>`).
