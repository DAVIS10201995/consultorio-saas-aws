# Pruebas de endurecimiento: cabeceras, límite de solicitudes y expiración del token.
# Uso: powershell -ExecutionPolicy Bypass -File pruebas\endurecimiento.ps1
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
        return [pscustomobject]@{ Estado = [int]$r.StatusCode; Datos = $datos; Cabeceras = $r.Headers }
    } catch {
        $resp = $_.Exception.Response
        if ($resp) { return [pscustomobject]@{ Estado = [int]$resp.StatusCode; Datos = $null; Cabeceras = $null } }
        throw
    }
}

$resultados = @()
function Anotar([string]$Prueba, [string]$Esperado, [string]$Obtenido) {
    $script:resultados += [pscustomobject]@{
        Prueba = $Prueba; Esperado = $Esperado; Obtenido = $Obtenido
        Resultado = $(if ($Esperado -eq $Obtenido) { "OK" } else { "FALLA" })
    }
}

# 1. Sesión válida: expiración del token y cabeceras de seguridad
$pass = Read-Host "Contraseña de lector@sonrisa.test"
$login = Llamar "POST" "/login" $null @{ email = "lector@sonrisa.test"; password = $pass }
Write-Host "  -> token emitido; expira en: $($login.Datos.expira_en)"

$r = Llamar "GET" "/yo" $login.Datos.token $null
Anotar "GET /yo con sesión" "200" "$($r.Estado)"
$c = $r.Cabeceras
Anotar "X-Content-Type-Options" "nosniff" "$($c['X-Content-Type-Options'])"
Anotar "X-Frame-Options" "DENY" "$($c['X-Frame-Options'])"
Anotar "Referrer-Policy" "no-referrer" "$($c['Referrer-Policy'])"
Anotar "Cache-Control" "no-store, private" "$($c['Cache-Control'])"
Anotar "X-Powered-By (no debe existir)" "" "$($c['X-Powered-By'])"
Write-Host "  -> cabecera Server: $($c['Server'])  (no debe mostrar versión)"

# 2. Fuerza bruta: 6 intentos fallidos seguidos contra una cuenta inexistente
$estados = @()
for ($i = 1; $i -le 6; $i++) {
    $r = Llamar "POST" "/login" $null @{ email = "fuerza.bruta@sonrisa.test"; password = "incorrecta$i" }
    $estados += $r.Estado
}
Write-Host "  -> estados de los 6 intentos: $($estados -join ', ')"
Anotar "Intentos 1 a 5 de login fallido" "401,401,401,401,401" (($estados[0..4]) -join ',')
Anotar "Intento 6 de login fallido" "429" "$($estados[5])"

$resultados | Format-Table -AutoSize
