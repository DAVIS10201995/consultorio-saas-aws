# #17 S3 y CloudFront con OAC

Responsable: Persona B. Plantilla: `infra/cloudformation/04-cdn.yaml`.

## Qué crea

| Recurso | Configuración |
|---|---|
| Bucket `consultorio-frontend-<cuenta>` | Acceso público bloqueado, cifrado, solo TLS. Solo lo lee esta distribución (`AWS:SourceArn`) |
| OAC | CloudFront firma cada petición a S3 (SigV4) |
| Distribución, ruta `/*` | Bucket S3, redirección a HTTPS, caché y cabeceras de seguridad (HSTS, CSP, etc.) |
| Distribución, ruta `/api/*` | EC2, solo HTTPS del lado del usuario, sin caché, reenvía `Authorization` |

## 1. Desplegar

1. CloudFormation (us-east-2) > **Create stack** > **With new resources (standard)** > subir `infra/cloudformation/04-cdn.yaml`.
2. **Stack name:** `consultorio-cdn`. Parámetros sin cambios, tag `Proyecto` = `consultorio-saas`.
3. **Submit** y esperar **CREATE_COMPLETE**.
4. 📸 `17-outputs.png`: pestaña **Outputs**, con el número de cuenta tapado.

## 2. Compilar y subir el frontend

Requiere los cambios de `main` en la carpeta. En PowerShell, desde la raíz del repositorio:

```powershell
npm.cmd --prefix frontend ci
```

```powershell
npm.cmd --prefix frontend run build
```

En S3, abrir el bucket `BucketFrontend` > **Upload** y arrastrar **el contenido** de `frontend/dist` (`index.html` y la carpeta `assets`), no la carpeta `dist`.

Al publicar una versión nueva: CloudFront > la distribución > **Invalidations** > crear una con `/index.html` y `/`.

## 3. Pruebas (P05)

Cambiar los valores por los de **Outputs**.

| Prueba | Comando (PowerShell) | Esperado |
|---|---|---|
| S3 directo | `curl.exe -s -o NUL -w "%{http_code}" URL_DIRECTA_S3` | `403` |
| CloudFront | `curl.exe -sI URL_PUBLICA/` | `200`, con `strict-transport-security` y `content-security-policy` |
| HTTP | `curl.exe -sI http://DOMINIO/` | `301` a `https://` |
| API | `curl.exe -sI URL_PUBLICA/api/salud` | Antes del #20: `502` **con** `x-request-id`. Lo agrega nginx, así que demuestra que la petición llegó a la EC2. Después del #20: `200` |

El `403` directo solo vale como evidencia junto con el `200` por CloudFront del **mismo archivo**, porque S3 también responde 403 si el objeto no existe.

Capturas: `17-s3-directo.png`, `17-cloudfront.png`, `17-http.png`, `17-api.png`, y `17-bucket-politica.png` (S3 > Permissions).

## Limitaciones documentadas

- **CloudFront a EC2 por HTTP.** Ese tramo no va cifrado, porque sin dominio propio la EC2 no tiene un certificado que CloudFront acepte.
- **La lista de prefijos cubre a todo CloudFront**, no solo a nuestra distribución. Alguien que conozca el DNS de la EC2 podría apuntar su propia distribución hacia ella. La autenticación y los roles de la API siguen protegiendo los datos. Mejora posible: una cabecera secreta que CloudFront agregue y nginx exija.

## Retiro (#33)

Vaciar el bucket y borrar `consultorio-cdn` **antes** que `consultorio-ec2`, porque importa su DNS.
