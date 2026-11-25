# #19 DNS y CloudTrail

Responsable: Persona B.

## DNS

No compramos dominio. Cada recurso usa el nombre DNS que le da AWS:

| Recurso | Nombre DNS | A dónde resuelve | Quién puede conectarse |
|---|---|---|---|
| CloudFront | `d2jl6a8fvk3fdl.cloudfront.net` | IP públicas de CloudFront | Cualquiera, por HTTPS |
| EC2 (origen de `/api/*`) | Output `DnsPublico` de `consultorio-ec2` | IP elástica desde Internet; IP privada desde la VPC | Solo CloudFront, puerto 80 |
| RDS | `consultorio-pg.c7m4kgqqc8wy.us-east-2.rds.amazonaws.com` | IP privada (10.20.x.x) | Solo la EC2, puerto 5432 |
| S3 frontend | `consultorio-frontend-<cuenta>.s3.us-east-2.amazonaws.com` | IP públicas de S3 | Solo CloudFront (OAC); los demás reciben 403 |

Que un nombre resuelva no significa que se pueda entrar: lo deciden la red, el grupo de seguridad o la política del recurso.

### Cómo sería con Route 53

1. Comprar un dominio y crear una zona pública alojada.
2. Pedir un certificado en ACM, **en us-east-1** (requisito de CloudFront), validado por DNS.
3. Agregar el dominio como nombre alternativo de la distribución y asociar el certificado.
4. Crear registros Alias `A` y `AAAA` hacia CloudFront.

No lo hicimos: el dominio y la zona cuestan, y `*.cloudfront.net` ya da HTTPS válido. Falta confirmar si nuestro tipo de cuenta, con la región fija, permite ACM en us-east-1.

## CloudTrail (P10)

Cambio de prueba: agregar y quitar una regla en `consultorio-ec2-desde-cloudfront`. El origen `203.0.113.10/32` es un rango reservado para documentación, así que no da acceso a nadie real.

| Dato | Agregar | Quitar |
|---|---|---|
| Evento | `AuthorizeSecurityGroupIngress` | `RevokeSecurityGroupIngress` |
| Hora (UTC) | 2026-11-24 21:46:30 | 2026-11-24 21:47:28 |
| Hora (Ciudad de México) | 15:46:30 | 15:47:28 |
| Identidad | Rol `AccountFullAccessRole` asumido desde la consola por el Builder ID de Persona B | Rol `AccountFullAccessRole` asumido desde la consola por el Builder ID de Persona B |
| Regla | `sgr-06663f87222219343` (TCP 8080, `203.0.113.10/32`) | La misma |
| Resultado | `_return: true` | `_return: true` |
| IP de origen | (tapada) | (tapada) |
| Recurso | `consultorio-ec2-desde-cloudfront` | `consultorio-ec2-desde-cloudfront` |

La regla estuvo activa 58 segundos. El mismo ID en los dos eventos demuestra que se quitó justo la que se agregó.

**Qué muestra la identidad.** `userIdentity.type` es `AssumedRole`: no hay root ni usuario IAM. El campo `onBehalfOf.userId` distingue a la persona detrás del rol, así que cada integrante debe usar su propio Builder ID.

**Sobre `mfaAuthenticated: "false"`.** Ese campo describe la sesión del rol en AWS. En un inicio de sesión federado no refleja necesariamente el MFA hecho antes en el proveedor de identidad. Este evento no prueba que se usó MFA ni que se omitió; la evidencia es `02-mfa-builder-id.png` junto con un inicio de sesión donde se pida el código.

**Riesgo observado.** La sesión usa un rol de acceso total. Los permisos mínimos del proyecto se aplican a los servicios (rol de la EC2) y a las cuentas de PostgreSQL, no a la persona dueña.

Evidencias: `19-dns.png`, `19-cambio.png`, `19-cloudtrail-agregar.png`, `19-cloudtrail-quitar.png`.
