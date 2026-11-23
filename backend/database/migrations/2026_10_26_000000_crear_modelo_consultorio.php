<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE usuarios (
  id BIGSERIAL PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  rol VARCHAR(20) NOT NULL CHECK (rol IN ('recepcion','dentista','admin')),
  activo BOOLEAN NOT NULL DEFAULT TRUE,
  creado_en TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE pacientes (
  id BIGSERIAL PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  telefono VARCHAR(30),
  email VARCHAR(160),
  creado_en TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE planes_tratamiento (
  id BIGSERIAL PRIMARY KEY,
  paciente_id BIGINT NOT NULL REFERENCES pacientes(id),
  dentista_id BIGINT NOT NULL REFERENCES usuarios(id),
  descripcion VARCHAR(255) NOT NULL,
  sesiones_total SMALLINT NOT NULL CHECK (sesiones_total > 0),
  estado VARCHAR(20) NOT NULL DEFAULT 'activo' CHECK (estado IN ('activo','completado','cancelado')),
  creado_en TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE citas (
  id BIGSERIAL PRIMARY KEY,
  paciente_id BIGINT NOT NULL REFERENCES pacientes(id),
  dentista_id BIGINT NOT NULL REFERENCES usuarios(id),
  plan_id BIGINT REFERENCES planes_tratamiento(id),
  inicio TIMESTAMPTZ NOT NULL,
  fin TIMESTAMPTZ NOT NULL,
  estado VARCHAR(25) NOT NULL DEFAULT 'programada' CHECK (estado IN ('programada','pendiente_confirmacion','confirmada','en_atencion','completada','cancelada','no_asistio')),
  motivo VARCHAR(255),
  creado_en TIMESTAMPTZ NOT NULL DEFAULT now(),
  CHECK (fin > inicio),
  CONSTRAINT citas_sin_traslape EXCLUDE USING gist (dentista_id WITH =, tstzrange(inicio, fin) WITH &&) WHERE (estado NOT IN ('cancelada','no_asistio'))
);
CREATE INDEX citas_paciente_idx ON citas (paciente_id, inicio);

CREATE TABLE registros_clinicos (
  id BIGSERIAL PRIMARY KEY,
  cita_id BIGINT NOT NULL REFERENCES citas(id),
  dentista_id BIGINT NOT NULL REFERENCES usuarios(id),
  procedimiento VARCHAR(120) NOT NULL,
  notas TEXT,
  creado_en TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE outbox (
  id BIGSERIAL PRIMARY KEY,
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
        DB::unprepared('DROP TABLE IF EXISTS outbox, registros_clinicos, citas, planes_tratamiento, pacientes, usuarios CASCADE');
    }
};