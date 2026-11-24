<?php

namespace App\Services;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\DynamoDb\Marshaler;
use Carbon\Carbon;
use RuntimeException;

/**
 * Bitácora de eventos de citas en DynamoDB (tabla consultorio-bitacora).
 *   PK = ORG#<organizacion_id>#CITA#<cita_id>
 *   SK = EVT#<fecha UTC ISO 8601>#<event_id>
 * La PK siempre se arma en el servidor con la organización autorizada.
 */
class Bitacora
{
    private DynamoDbClient $cliente;
    private Marshaler $marshaler;
    private string $tabla;
    private bool $esLocal;

    public function __construct()
    {
        $opciones = ['region' => config('services.dynamodb.region'), 'version' => 'latest'];

        // Solo en local hay endpoint (DynamoDB Local). En AWS se usa el rol de la EC2.
        $endpoint = config('services.dynamodb.endpoint');
        if ($endpoint) {
            $opciones['endpoint'] = $endpoint;
        }

        $this->esLocal = (bool) $endpoint;
        $this->cliente = new DynamoDbClient($opciones);
        $this->marshaler = new Marshaler;
        $this->tabla = config('services.dynamodb.tabla');
    }

    public static function clave(int $organizacionId, int $citaId): string
    {
        return "ORG#{$organizacionId}#CITA#{$citaId}";
    }

    /**
     * Guarda un evento sin duplicarlo.
     *
     * @return bool true si se escribió; false si ya existía (reenvío idempotente).
     */
    public function registrar(int $organizacionId, string $eventId, string $tipo, array $payload): bool
    {
        // Fecha en UTC: así el orden alfabético de la SK coincide con el cronológico.
        $fecha = Carbon::parse($payload['fecha'])->utc()->format('Y-m-d\TH:i:s.u\Z');
        $citaId = (int) $payload['cita_id'];

        $item = [
            'PK' => self::clave($organizacionId, $citaId),
            'SK' => "EVT#{$fecha}#{$eventId}",
            'event_id' => $eventId,
            'tipo' => $tipo,
            'organizacion_id' => $organizacionId,
            'cita_id' => $citaId,
            'paciente_id' => (int) $payload['paciente_id'],
            'dentista_id' => (int) $payload['dentista_id'],
            'usuario_id' => (int) $payload['usuario_id'],
            'estado_anterior' => $payload['estado_anterior'] ?? null,
            'estado_nuevo' => $payload['estado_nuevo'],
            'fecha' => $fecha,
        ];

        try {
            $this->cliente->putItem([
                'TableName' => $this->tabla,
                'Item' => $this->marshaler->marshalItem($item),
                'ConditionExpression' => 'attribute_not_exists(PK)',
            ]);

            return true;
        } catch (DynamoDbException $e) {
            if ($e->getAwsErrorCode() === 'ConditionalCheckFailedException') {
                return false;
            }
            throw $e;
        }
    }

    /** Historial de una cita en orden cronológico. */
    public function historial(int $organizacionId, int $citaId): array
    {
        $eventos = [];
        $desde = null;

        do {
            $parametros = [
                'TableName' => $this->tabla,
                'KeyConditionExpression' => 'PK = :pk',
                'ExpressionAttributeValues' => [':pk' => ['S' => self::clave($organizacionId, $citaId)]],
                'ScanIndexForward' => true,
            ];
            if ($desde) {
                $parametros['ExclusiveStartKey'] = $desde;
            }

            $respuesta = $this->cliente->query($parametros);
            foreach ($respuesta['Items'] as $item) {
                $e = $this->marshaler->unmarshalItem($item);
                $eventos[] = [
                    'fecha' => $e['fecha'],
                    'tipo' => $e['tipo'],
                    'estado_anterior' => $e['estado_anterior'] ?? null,
                    'estado_nuevo' => $e['estado_nuevo'],
                    'usuario_id' => $e['usuario_id'],
                    'event_id' => $e['event_id'],
                ];
            }
            $desde = $respuesta['LastEvaluatedKey'] ?? null;
        } while ($desde);

        return $eventos;
    }

    /** Crea la tabla. SOLO para DynamoDB Local: en AWS la tabla se crea en la consola. */
    public function crearTablaLocal(): void
    {
        if (! $this->esLocal) {
            throw new RuntimeException('crearTablaLocal solo funciona con DYNAMODB_ENDPOINT (DynamoDB Local).');
        }

        $this->cliente->createTable([
            'TableName' => $this->tabla,
            'AttributeDefinitions' => [
                ['AttributeName' => 'PK', 'AttributeType' => 'S'],
                ['AttributeName' => 'SK', 'AttributeType' => 'S'],
            ],
            'KeySchema' => [
                ['AttributeName' => 'PK', 'KeyType' => 'HASH'],
                ['AttributeName' => 'SK', 'KeyType' => 'RANGE'],
            ],
            'BillingMode' => 'PAY_PER_REQUEST',
        ]);
        $this->cliente->waitUntil('TableExists', ['TableName' => $this->tabla]);
    }
}
