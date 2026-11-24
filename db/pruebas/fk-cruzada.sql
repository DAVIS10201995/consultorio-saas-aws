-- Prueba: la base rechaza una cita de un consultorio con un paciente de otro.
-- Corre como consultorio_app y deshace todo al final (ROLLBACK).
BEGIN;
INSERT INTO organizaciones (nombre, slug) VALUES
  ('Clinica Dental Sonrisa', 'sonrisa'),
  ('Consultorio Dental Norte', 'norte');
INSERT INTO usuarios (nombre, email, password)
  VALUES ('Dentista de prueba', 'dentista@sonrisa.test', 'x');
INSERT INTO membresias (organizacion_id, usuario_id, rol, es_dentista)
  SELECT o.id, u.id, 'capturista', TRUE
  FROM organizaciones o, usuarios u
  WHERE o.slug = 'sonrisa' AND u.email = 'dentista@sonrisa.test';
INSERT INTO pacientes (organizacion_id, nombre)
  SELECT id, 'Paciente de Norte' FROM organizaciones WHERE slug = 'norte';

\echo '--- Intento: cita de SONRISA con paciente de NORTE (debe fallar) ---'
INSERT INTO citas (organizacion_id, paciente_id, dentista_id, inicio, fin)
  SELECT o.id, p.id, u.id, now() + interval '1 day', now() + interval '1 day 30 minutes'
  FROM organizaciones o, pacientes p, usuarios u
  WHERE o.slug = 'sonrisa' AND p.nombre = 'Paciente de Norte' AND u.email = 'dentista@sonrisa.test';
ROLLBACK;
