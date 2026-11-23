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
| 2 | `consultorio-datos` | `cloudformation/02-datos.yaml` (pendiente) | #15, #16 |
| 3 | `consultorio-app` | `cloudformation/03-app.yaml` (pendiente) | #14 |
| 4 | `consultorio-cdn` | `cloudformation/04-cdn.yaml` (pendiente) | #17, #18 |

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

Después crea el stack, poniendo ese ID y tu IP pública (por ejemplo, de `curl ifconfig.me`) con `/32` al final:

```powershell
aws cloudformation deploy --region us-east-2 --profile consultorio --stack-name consultorio-red --template-file infra/cloudformation/01-red.yaml --parameter-overrides CloudFrontPrefixListId=pl-XXXXXXXX AdminCidr=203.0.113.10/32 --tags Proyecto=consultorio-saas
```

Para comprobar las salidas:

```powershell
aws cloudformation describe-stacks --region us-east-2 --profile consultorio --stack-name consultorio-red --query "Stacks[0].Outputs" --output table
```

## Costos que evitamos a propósito

- **Sin NAT Gateway**, que cuesta unos 32 USD al mes aunque no se use. La RDS en subred privada no necesita salir a Internet.
- **Una sola IPv4 pública**, la de la EC2. Cada IPv4 pública cuesta cerca de 3.6 USD al mes.
- Instancias de tamaño `micro`, dentro del plan gratuito.

## Retiro (#33)

Se borra en orden inverso (`04` → `01`):

```powershell
aws cloudformation delete-stack --region us-east-2 --profile consultorio --stack-name consultorio-red
```

También hay que borrar a mano lo que no crea CloudFormation: los snapshots manuales de RDS, la instancia temporal de restauración y los recursos de Elastic Beanstalk (su grupo de seguridad y el bucket `elasticbeanstalk-us-east-2-<cuenta>`).
