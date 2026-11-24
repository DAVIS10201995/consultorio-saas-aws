# Prueba P01 y P03 (historial): cita creada en Sonrisa, eventos en DynamoDB.
# Uso: powershell -ExecutionPolicy Bypass -File pruebas\bitacora.ps1
param([string]$Base = "http://localhost:8080/api")

function Llamar([string]$Metodo, [string]$Ruta, [string]$Token, $Cuerpo) {
    $h = @{ Accept = "application/json" }
    if ($Token) { $h.Authorization = "Bearer $Token" }
    $p = @{ Method = $Metodo; Uri = "$Base$Ruta"; Headers = $h; ContentType = "application/json"; UseBasicParsing = $true }
    if ($null -ne $Cuerpo) { $p.Body = ($Cuerpo | ConvertTo-Json -Compress) }
    try {
        $r = Invoke-WebRequest @p
        $datos = $null
        if ($r.Content) { $datos = $r.Content | ConvertFrom-Json }
        return [pscustomobject]@{ Estado = [int]$r.StatusCode; Datos = $datos }
    } catch {
        $resp = $_.Exception.Response
        if ($resp) { return [pscustomobject]@{ Estado = [int]$resp.StatusCode; Datos = $null } }
        throw
    }
}

function Entrar([string]$Correo) {
    $pass = Read-Host "Contraseña de $Correo"
    $r = Llamar "POST" "/login" $null @{ email = $Correo; password = $pass }
    if ($r.Estado -ne 200) { throw "No se pudo entrar como $Correo (HTTP $($r.Estado))" }
    Write-Host "  -> $Correo entró como $($r.Datos.rol) en $($r.Datos.organizacion.nombre)"
    return $r.Datos
}

$resultados = @()
function Anotar([string]$Id, [string]$Prueba, [int]$Esperado, [int]$Obtenido) {
    $script:resultados += [pscustomobject]@{
        ID = $Id; Prueba = $Prueba; Esperado = $Esperado; Obtenido = $Obtenido
        Resultado = $(if ($Esperado -eq $Obtenido) { "OK" } else { "FALLA" })
    }
}

Write-Host "Inicios de sesión:"
$dentA = Entrar "dentista1@sonrisa.test"
$lecA = Entrar "lector@sonrisa.test"
$capB = Entrar "capturista@norte.test"

# P01: la dentista de Sonrisa (rol capturista) agenda y atiende una cita
$pac = (Llamar "GET" "/pacientes" $dentA.token $null).Datos.data
if (-not $pac) { $pac = (Llamar "GET" "/pacientes" $lecA.token $null).Datos.data }
$pacienteId = $pac[0].id
$dia = (Get-Date).Date.AddDays((Get-Random -Minimum 40 -Maximum 300))
$hora = Get-Random -Minimum 9 -Maximum 17
$inicio = $dia.AddHours($hora).ToString("yyyy-MM-dd'T'HH:mm:sszzz")

$r = Llamar "POST" "/citas" $dentA.token @{ paciente_id = $pacienteId; dentista_id = $dentA.id; inicio = $inicio; duracion_min = 30 }
Anotar "P01" "Capturista/dentista A crea cita" 201 $r.Estado
$citaId = $r.Datos.id
Write-Host "  -> cita $citaId creada en la organizacion $($r.Datos.organizacion_id) para $inicio"

foreach ($estado in @("confirmada", "en_atencion", "completada")) {
    $r = Llamar "POST" "/citas/$citaId/estado" $dentA.token @{ estado = $estado }
    Anotar "P01" "Cambio de estado a $estado" 200 $r.Estado
}

Write-Host "  -> esperando 8 segundos a que el worker publique en DynamoDB..."
Start-Sleep -Seconds 8

# Historial: el lector de la misma organización lo consulta
$r = Llamar "GET" "/citas/$citaId/historial" $lecA.token $null
Anotar "P01" "Lector A consulta historial" 200 $r.Estado
Write-Host "  -> eventos en DynamoDB para la cita ${citaId}:"
$r.Datos.eventos | Format-Table fecha, estado_anterior, estado_nuevo, usuario_id -AutoSize | Out-String | Write-Host

# P03: un usuario de Norte no puede ver el historial de Sonrisa
$r = Llamar "GET" "/citas/$citaId/historial" $capB.token $null
Anotar "P03" "Capturista B consulta historial de A" 404 $r.Estado

$resultados | Format-Table -AutoSize
