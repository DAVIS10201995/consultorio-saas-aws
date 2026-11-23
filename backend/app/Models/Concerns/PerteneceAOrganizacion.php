<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Alcance por organización. Toda consulta de negocio debe pasar por
 * deOrganizacion($id) con la organización de la membresía autenticada.
 */
trait PerteneceAOrganizacion
{
    public function scopeDeOrganizacion(Builder $consulta, int $organizacionId): Builder
    {
        return $consulta->where($this->getTable().'.organizacion_id', $organizacionId);
    }
}
