# #2 Cuenta AWS segura

Responsable: Persona B. Las capturas van en `docs/evidencias/02-*.png`, con el ID de cuenta, los correos y las IP tapados.

## Datos de la cuenta (evidencia pedida)

| Dato | Valor |
|---|---|
| Tipo de cuenta | Experiencia nueva de AWS (proyectos con AWS Builder ID) |
| Plan | Plan gratuito (Free plan) |
| Créditos al iniciar | 100 USD |
| Días restantes al iniciar | 182 (fecha de consulta: 2026-11-23) |
| Región | us-east-2 (Ohio), asignada por AWS al proyecto |
| Proyecto | Ctrl Shift Create |

Evidencia: `02-plan-creditos.png` (menú de la cuenta con "Free plan status") y la dirección de la consola con la región.

## Restricción del tipo de cuenta (va en el informe)

La cuenta se creó con la experiencia nueva de AWS. Eso cambia cómo se cumple el checklist:

1. **No hay root clásico.** El proyecto pertenece a un AWS Builder ID, que inicia sesión con Google.
2. **Los usuarios IAM solo sirven para acceso programático, no para la consola.** Por eso no se crean usuarios IAM para personas.
3. **La región está fija** en us-east-2. AWS la asigna según el país del contacto y no se puede cambiar.
4. **No activamos "advanced features".** Según la documentación de AWS, es irreversible, exige pasar al plan de pago y quita el límite de gasto del proyecto. Además, no hace falta: VPC, EC2, RDS, CloudFront, Elastic Beanstalk, DynamoDB, CloudTrail y Budgets están disponibles sin activarla.
5. **No se admiten funciones multirregión** (por ejemplo, Lambda@Edge). No afecta al diseño.

Fuentes: [Activate advanced features](https://docs.aws.amazon.com/accounts/latest/reference/activate-advanced-features.html), [Compare sign-up options](https://docs.aws.amazon.com/accounts/latest/reference/sign-up-for-aws.html), [Supported services](https://docs.aws.amazon.com/accounts/latest/reference/supported-services-sign-up-new.html), [Project Regions](https://docs.aws.amazon.com/accounts/latest/reference/project-regions.html).

## Checklist

| Punto de la práctica | Cómo se cumplió | Evidencia |
|---|---|---|
| MFA en root | No existe root clásico. MFA (app autenticadora) registrado en el Builder ID dueño, en Profile > Security. | `02-mfa-builder-id.png` |
| Un usuario de trabajo por persona, con MFA | Persona B: su Builder ID (Owner). Persona A: su propio Builder ID, invitado desde Settings > Team (Member, sin permisos administrativos ni financieros). Cada quien con su MFA. No se comparten credenciales. | `02-team-miembros.png`, `02-mfa-persona-a.png` |
| Alerta de presupuesto | AWS Budgets `consultorio-5usd`: mensual, 5 USD, créditos excluidos. Alertas al 80 % real y al 100 % pronosticado, a los dos correos. | `02-presupuesto.png` |
| Región elegida | us-east-2, fijada por AWS. | `02-plan-creditos.png` |
| Plan, créditos y región anotados | Tabla de arriba. | `02-plan-creditos.png` |

## Medidas adicionales

- [ ] La cuenta de Google del Builder ID dueño tiene verificación en dos pasos (es la puerta de entrada real).
- [ ] Correo de recuperación agregado en Profile > Security.
- [ ] Usuarios IAM de prueba (`david`, `sandra`) y la llave de acceso creada por error, **borrados**.
- [ ] CloudTrail > Event history muestra los eventos de la configuración (`CreateBudget`, `DeleteUser`, `DeleteAccessKey`). Captura: `02-cloudtrail.png`.

## IAM en el resto del proyecto

IAM se usa **solo para roles de servicios**, no para personas:

- Rol de instancia EC2 con `PutItem` y `Query` sobre la tabla de DynamoDB (#16).
- Rol de servicio y perfil de instancia de Elastic Beanstalk (#25).

Para la terminal se usa `aws login` con el Builder ID (credenciales temporales), sin llaves de acceso guardadas en disco.
