# Indicador de disponibilidad (#23): serie de comprobaciones a /api/salud.
# Mide el % de respuestas correctas (200), la latencia y, si hubo fallas, cuánto tardó en recuperarse.
# Uso:
#   powershell -ExecutionPolicy Bypass -File pruebas\disponibilidad.ps1
#   powershell -ExecutionPolicy Bypass -File pruebas\disponibilidad.ps1 -Intentos 120 -IntervaloSeg 2
param(
    [string]$Url = "https://d2jl6a8fvk3fdl.cloudfront.net/api/salud",
    [int]$Intentos = 60,
    [double]$IntervaloSeg = 5,
    [int]$TimeoutSeg = 5,
    [string]$Salida = ""
)

if (-not $Salida) {
    $carpeta = Join-Path $PSScriptRoot "resultados"
    New-Item -ItemType Directory -Force -Path $carpeta | Out-Null
    $Salida = Join-Path $carpeta ("disponibilidad-" + (Get-Date -Format "yyyyMMdd-HHmmss") + ".csv")
}

Write-Host "Comprobando $Url : $Intentos intentos, cada $IntervaloSeg s (timeout $TimeoutSeg s)"
$muestras = @()

# try/finally: si se interrumpe con Ctrl+C, igual guarda el CSV y muestra el resumen.
try {
for ($i = 1; $i -le $Intentos; $i++) {
    $reloj = [System.Diagnostics.Stopwatch]::StartNew()
    $estado = 0
    $id = ""
    try {
        $r = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec $TimeoutSeg -Headers @{ Accept = "application/json" }
        $estado = [int]$r.StatusCode
        $id = "$($r.Headers['X-Request-Id'])"
    } catch {
        $resp = $_.Exception.Response
        if ($resp) { $estado = [int]$resp.StatusCode }   # 0 = sin respuesta (timeout o conexión rechazada)
    }
    $reloj.Stop()

    $m = [pscustomobject]@{
        N = $i
        Hora = (Get-Date).ToString("yyyy-MM-ddTHH:mm:ss.fffzzz")
        Estado = $estado
        Ok = ($estado -eq 200)
        LatenciaMs = [math]::Round($reloj.Elapsed.TotalMilliseconds, 1)
        RequestId = $id
    }
    $muestras += $m
    $marca = $(if ($m.Ok) { "ok " } else { "FALLA" })
    Write-Host ("{0,3}  {1}  {2}  {3,4}  {4,7} ms" -f $i, $m.Hora, $marca, $estado, $m.LatenciaMs)

    if ($i -lt $Intentos) {
        $espera = [math]::Max(0, $IntervaloSeg * 1000 - $reloj.Elapsed.TotalMilliseconds)
        Start-Sleep -Milliseconds ([int]$espera)
    }
}

} finally {
if ($muestras.Count -gt 0) {
$muestras | Export-Csv -Path $Salida -NoTypeInformation -Encoding UTF8

# Resumen
$ok = @($muestras | Where-Object { $_.Ok })
$porcentaje = [math]::Round(100 * $ok.Count / $muestras.Count, 2)
$lat = @($ok | ForEach-Object { $_.LatenciaMs } | Sort-Object)
function Percentil($lista, $p) {
    if ($lista.Count -eq 0) { return $null }
    $idx = [math]::Ceiling($p / 100 * $lista.Count) - 1
    return $lista[[math]::Max(0, $idx)]
}

# Ventanas de caída: desde la primera falla hasta la siguiente respuesta correcta
$caidas = @()
$inicioCaida = $null
foreach ($m in $muestras) {
    if (-not $m.Ok -and -not $inicioCaida) { $inicioCaida = [datetime]::Parse($m.Hora) }
    if ($m.Ok -and $inicioCaida) {
        $caidas += [pscustomobject]@{
            Desde = $inicioCaida.ToString("HH:mm:ss")
            Hasta = ([datetime]::Parse($m.Hora)).ToString("HH:mm:ss")
            RecuperacionSeg = [math]::Round((([datetime]::Parse($m.Hora)) - $inicioCaida).TotalSeconds, 1)
        }
        $inicioCaida = $null
    }
}

Write-Host ""
Write-Host "==== Resumen ===="
Write-Host "Comprobaciones:          $($muestras.Count)"
Write-Host "Respuestas correctas:    $($ok.Count)"
Write-Host "Disponibilidad medida:   $porcentaje %"
if ($lat.Count -gt 0) {
    Write-Host "Latencia p50 / p95 / max: $(Percentil $lat 50) / $(Percentil $lat 95) / $($lat[-1]) ms"
}
if ($caidas.Count -gt 0) {
    Write-Host "Caídas detectadas y tiempo de recuperación:"
    $caidas | Format-Table -AutoSize | Out-String | Write-Host
} elseif ($inicioCaida) {
    Write-Host "La serie terminó con el servicio caído desde $($inicioCaida.ToString('HH:mm:ss')) (sin recuperación medida)."
} else {
    Write-Host "Sin caídas durante la serie."
}
Write-Host "Detalle guardado en: $Salida"
}
}
