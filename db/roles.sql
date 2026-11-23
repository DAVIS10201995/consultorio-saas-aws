-- Cuentas de PostgreSQL con mínimo privilegio.
-- Se usa igual en local (al iniciar el contenedor) y en RDS (con psql desde la EC2):
--   psql -v ON_ERROR_STOP=1 -v mig_pw=... -v app_pw=... -d consultorio -f db/roles.sql
--
-- consultorio_migrador: dueño del esquema, crea y modifica tablas (solo migraciones).
-- consultorio_app: la aplicación; lee y escribe datos, NO puede crear ni borrar tablas.

SELECT 'CREATE ROLE consultorio_migrador LOGIN'
 WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'consultorio_migrador') \gexec
SELECT 'CREATE ROLE consultorio_app LOGIN'
 WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'consultorio_app') \gexec

ALTER ROLE consultorio_migrador PASSWORD :'mig_pw';
ALTER ROLE consultorio_app PASSWORD :'app_pw';

-- Necesaria para la restricción de no traslape de citas.
CREATE EXTENSION IF NOT EXISTS btree_gist;

REVOKE ALL ON DATABASE consultorio FROM PUBLIC;
GRANT CONNECT ON DATABASE consultorio TO consultorio_migrador, consultorio_app;

REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE, CREATE ON SCHEMA public TO consultorio_migrador;
GRANT USAGE ON SCHEMA public TO consultorio_app;

-- Lo que cree el migrador, la app lo puede usar (pero no alterar).
ALTER DEFAULT PRIVILEGES FOR ROLE consultorio_migrador IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO consultorio_app;
ALTER DEFAULT PRIVILEGES FOR ROLE consultorio_migrador IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO consultorio_app;
