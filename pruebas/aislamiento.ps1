# Pruebas P02, P03 y P04 contra la API local (http://localhost:8080).
# Uso: powershell -ExecutionPolicy Bypass -File pruebas\aislamiento.ps1
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
    return $r.Datos.token
}

$resultados = @()
function Anotar([string]$Id, [string]$Prueba, [int]$Esperado, [int]$Obtenido) {
    $script:resultados += [pscustomobject]@{
        ID = $Id; Prueba = $Prueba; Esperado = $Esperado; Obtenido = $Obtenido
        Resultado = $(if ($Esperado -eq $Obtenido) { "OK" } else { "FALLA" })
    }
}

Write-Host "Inicios de sesión (las contraseñas las imprimió datos:sembrar):"
$capA = Entrar "capturista@sonrisa.test"
$lecA = Entrar "lector@sonrisa.test"
$capB = Entrar "capturista@norte.test"

# P04a: ruta protegida sin sesión
$r = Llamar "GET" "/pacientes" $null $null
Anotar "P04" "Sin sesion: GET /pacientes" 401 $r.Estado

# Preparación: la capturista de Sonrisa crea un paciente
$r = Llamar "POST" "/pacientes" $capA @{ nombre = "Paciente Prueba Aislamiento"; telefono = "555-0199" }
Anotar "prep" "Capturista A crea paciente" 201 $r.Estado
$id = $r.Datos.id
Write-Host "  -> paciente creado en Sonrisa con id $id"

# P02: el lector consulta y no puede modificar
$r = Llamar "GET" "/pacientes/$id" $lecA $null
Anotar "P02" "Lector A consulta el paciente" 200 $r.Estado
$r = Llamar "PUT" "/pacientes/$id" $lecA @{ nombre = "Nombre cambiado por lector" }
Anotar "P02" "Lector A intenta modificar" 403 $r.Estado
$r = Llamar "GET" "/pacientes/$id" $capA $null
$sigue = $r.Datos.nombre -eq "Paciente Prueba Aislamiento"
Write-Host "  -> el nombre sigue igual despues del intento del lector: $sigue"

# P03: un usuario de Norte no ve ni modifica el registro de Sonrisa
$r = Llamar "GET" "/pacientes/$id" $capB $null
Anotar "P03" "Capturista B consulta paciente de A" 404 $r.Estado
$r = Llamar "PUT" "/pacientes/$id" $capB @{ nombre = "Cambiado desde Norte" }
Anotar "P03" "Capturista B modifica paciente de A" 404 $r.Estado
$r = Llamar "GET" "/pacientes?buscar=Aislamiento" $capB $null
Write-Host "  -> resultados de la busqueda desde Norte: $($r.Datos.total) (debe ser 0)"

# P04b: entrada inválida con sesión autorizada
$r = Llamar "POST" "/pacientes" $capA @{ nombre = "" }
Anotar "P04" "Entrada invalida (nombre vacio)" 422 $r.Estado

Write-Host ""
$resultados | Format-Table -AutoSize
