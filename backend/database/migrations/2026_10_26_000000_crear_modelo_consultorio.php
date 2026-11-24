<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Modelo multi-organización del consultorio SaaS.
 * Todas las tablas de negocio llevan organizacion_id y las llaves foráneas
 * compuestas (organizacion_id, x_id) impiden que un registro de un consultorio
 * apunte a un registro de otro, aunque la aplicación tenga un error.
 * Se ejecuta con la cuenta consultorio_migrador (dueña del esquema).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE organizaciones (
  id BIGSERIAL PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  slug VARCHAR(40) NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9-]+$'),
  creado_en TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE usuarios (
  id BIGSERIAL PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  activo BOOLEAN NOT NULL DEFAULT TRUE,
  creado_en TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE membresias (
  id BIGSERIAL PRIMARY KEY,
  organizacion_id BIGINT NOT NULL REFERENCES organizaciones(id),
  usuario_id BIGINT NOT NULL UNIQUE REFERENCES usuarios(id),
  rol VARCHAR(20) NOT NULL CHECK (rol IN ('admin','capturista','lector')),
  es_dentista BOOLEAN NOT NULL DEFAULT FALSE,
  creado_en TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (organizacion_id, usuario_id)
);

CREATE TABLE pacientes (
  id BIGSERIAL PRIMARY KEY,
  organizacion_id BIGINT NOT NULL REFERENCES organizaciones(id),
  nombre VARCHAR(120) NOT NULL,
  telefono VARCHAR(30),
  email VARCHAR(160),
  creado_en TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (organizacion_id, id)
);
CREATE INDEX pacientes_org_nombre_idx ON pacientes (organizacion_id, nombre);

CREATE TABLE planes_tratamiento (
  id BIGSERIAL PRIMARY KEY,
  organizacion_id BIGINT NOT NULL,
  paciente_id BIGINT NOT NULL,
  dentista_id BIGINT NOT NULL,
  descripcion VARCHAR(255) NOT NULL,
  sesiones_total SMALLINT NOT NULL CHECK (sesiones_total > 0),
  estado VARCHAR(20) NOT NULL DEFAULT 'activo' CHECK (estado IN ('activo','completado','cancelado')),
  creado_en TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (organizacion_id, id),
  FOREIGN KEY (organizacion_id, paciente_id) REFERENCES pacientes (organizacion_id, id),
  FOREIGN KEY (organizacion_id, dentista_id) REFERENCES membresias (organizacion_id, usuario_id)
);

CREATE TABLE citas (
  id BIGSERIAL PRIMARY KEY,
  organizacion_id BIGINT NOT NULL,
  paciente_id BIGINT NOT NULL,
  dentista_id BIGINT NOT NULL,
  plan_id BIGINT,
  inicio TIMESTAMPTZ NOT NULL,
  fin TIMESTAMPTZ NOT NULL,
  estado VARCHAR(25) NOT NULL DEFAULT 'programada' CHECK (estado IN ('programada','pendiente_confirmacion','confirmada','en_atencion','completada','cancelada','no_asistio')),
  motivo VARCHAR(255),
  creado_en TIMESTAMPTZ NOT NULL DEFAULT now(),
  CHECK (fin > inicio),
  UNIQUE (organizacion_id, id),
  FOREIGN KEY (organizacion_id, paciente_id) REFERENCES pacientes (organizacion_id, id),
  FOREIGN KEY (organizacion_id, dentista_id) REFERENCES membresias (organizacion_id, usuario_id),
  FOREIGN KEY (organizacion_id, plan_id) REFERENCES planes_tratamiento (organizacion_id, id),
  CONSTRAINT citas_sin_traslape EXCLUDE USING gist (dentista_id WITH =, tstzrange(inicio, fin) WITH &&)
    WHERE (estado NOT IN ('cancelada','no_asistio'))
);
CREATE INDEX citas_org_inicio_idx ON citas (organizacion_id, inicio);
CREATE INDEX citas_org_paciente_idx ON citas (organizacion_id, paciente_id, inicio);

CREATE TABLE registros_clinicos (
  id BIGSERIAL PRIMARY KEY,
  organizacion_id BIGINT NOT NULL,
  cita_id BIGINT NOT NULL,
  dentista_id BIGINT NOT NULL,
  procedimiento VARCHAR(120) NOT NULL,
  notas TEXT,
  creado_en TIMESTAMPTZ NOT NULL DEFAULT now(),
  FOREIGN KEY (organizacion_id, cita_id) REFERENCES citas (organizacion_id, id),
  FOREIGN KEY (organizacion_id, dentista_id) REFERENCES membresias (organizacion_id, usuario_id)
);

CREATE TABLE outbox (
  id BIGSERIAL PRIMARY KEY,
  organizacion_id BIGINT NOT NULL REFERENCES organizaciones(id),
  event_id UUID NOT NULL UNIQUE,
  tipo VARCHAR(60) NOT NULL,
  payload JSONB NOT NULL,
  creado_en TIMESTAMPTZ NOT NULL DEFAULT now(),
  publicado_en TIMESTAMPTZ,
  intentos INT NOT NULL DEFAULT 0
);
CREATE INDEX outbox_pendientes_idx ON outbox (id) WHERE publicado_en IS NULL;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS outbox, registros_clinicos, citas, planes_tratamiento, pacientes, membresias, usuarios, organizaciones CASCADE');
    }
};
