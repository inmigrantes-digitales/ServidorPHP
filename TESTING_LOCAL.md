# Testing Local — Guía de Pruebas

> Comandos listos para copiar/pegar en PowerShell para probar el backend localmente.

---

## 1. Iniciar el servidor

Desde `servidorPHP/`:

```powershell
& "C:\xampp\php\php.exe" -S localhost:8000 index.php
```

El servidor debe responder:
```
Development Server started at [Thu Mar 06 12:00:00 2026]
Listening on http://localhost:8000
Document root is C:\Users\mfrivera\Desktop\no ver\veronica\Acceso Senior Backend\servidorPHP
Press Ctrl-C to quit.
```

---

## 2. Pruebas sin autenticación

### Salud (root)
```powershell
curl http://localhost:8000/
```

Respuesta esperada:
```json
{"ok":true,"service":"Acceso Senior Backend (PHP)","version":"2.0.0"}
```

### Listar centros
```powershell
curl http://localhost:8000/api/centers
```

### Listar tipos de problema
```powershell
curl http://localhost:8000/api/problem-types
```

---

## 3. Autenticación

### Login con usuario de ejemplo (Consultante)
```powershell
$loginResp = curl -X POST http://localhost:8000/api/auth/login `
  -H "Content-Type: application/json" `
  -d '{"email":"pepe@gmail.com","password":"123456"}' | ConvertFrom-Json

$token = $loginResp.data.token
Write-Host "Token: $token"
```

### Login con facilitador
```powershell
$loginFac = curl -X POST http://localhost:8000/api/auth/login `
  -H "Content-Type: application/json" `
  -d '{"email":"juan_facilitador@gmail.com","password":"123456"}' | ConvertFrom-Json

$tokenFac = $loginFac.data.token
Write-Host "Token Facilitador: $tokenFac"
```

### Login con admin
```powershell
$loginAdmin = curl -X POST http://localhost:8000/api/auth/login `
  -H "Content-Type: application/json" `
  -d '{"email":"juan@gmail.com","password":"123456"}' | ConvertFrom-Json

$tokenAdmin = $loginAdmin.data.token
Write-Host "Token Admin: $tokenAdmin"
```

---

## 4. Endpoints de usuario (requieren auth)

### Obtener perfil propio
```powershell
curl http://localhost:8000/api/users/me `
  -H "Authorization: Bearer $token"
```

Respuesta incluye: `id`, `name`, `email`, `phone`, `dni`, `role`, `zone`, `address`

### Actualizar perfil con DNI
```powershell
curl -X PUT http://localhost:8000/api/users/me `
  -H "Content-Type: application/json" `
  -H "Authorization: Bearer $token" `
  -d '{"name":"Pepe García Updated","phone":"1164821749","dni":"12345678","zone":"Villa Crespo","address":"Calle Falsa 123"}'
```

### Registrar nuevo usuario
```powershell
curl -X POST http://localhost:8000/api/auth/register `
  -H "Content-Type: application/json" `
  -d '{
    "name":"Rosa María Pérez",
    "email":"rosa@ejemplo.com",
    "password":"miPassword789",
    "phone":"1199776655",
    "dni":"55555555",
    "role":"consultante",
    "zone":"San Telmo"
  }'
```

---

## 5. Endpoints de casos (requieren auth)

### Crear caso (sin autenticación)
```powershell
curl -X POST http://localhost:8000/api/cases `
  -H "Content-Type: application/json" `
  -d '{
    "consultante_id":2,
    "center_id":1,
    "problem_type_id":3,
    "description":"No puedo conectarme a internet desde la tablet",
    "input_method":"texto"
  }'
```

### Obtener mis casos (consultante)
```powershell
curl http://localhost:8000/api/cases/mine `
  -H "Authorization: Bearer $token"
```

### Obtener casos disponibles (facilitador)
```powershell
curl http://localhost:8000/api/cases/available `
  -H "Authorization: Bearer $tokenFac"
```

### Obtener mis casos asignados (facilitador)
```powershell
curl http://localhost:8000/api/cases/mine/facilitador `
  -H "Authorization: Bearer $tokenFac"
```

### Obtener todos los casos (admin)
```powershell
curl http://localhost:8000/api/cases/mine/admin `
  -H "Authorization: Bearer $tokenAdmin"
```

### Obtener caso por ID
```powershell
curl http://localhost:8000/api/cases/2 `
  -H "Authorization: Bearer $token"
```

### Asignar caso (facilitador)
```powershell
curl -X POST http://localhost:8000/api/cases/1/assign `
  -H "Content-Type: application/json" `
  -H "Authorization: Bearer $tokenFac" `
  -d '{}'
```

El caso 1 debería cambiar de `status: "ingresado"` a `status: "asignado"`.

### Cambiar estado de caso (facilitador/admin)
```powershell
# Cambiar a "proceso"
curl -X PUT http://localhost:8000/api/cases/1/status `
  -H "Content-Type: application/json" `
  -H "Authorization: Bearer $tokenFac" `
  -d '{"status":"proceso"}'

# Cambiar a "resuelto"
curl -X PUT http://localhost:8000/api/cases/1/status `
  -H "Content-Type: application/json" `
  -H "Authorization: Bearer $tokenFac" `
  -d '{"status":"resuelto"}'

# Cambiar a "cerrado"
curl -X PUT http://localhost:8000/api/cases/1/status `
  -H "Content-Type: application/json" `
  -H "Authorization: Bearer $tokenFac" `
  -d '{"status":"cerrado"}'
```

---

## 6. Pruebas del asistente IA (SSE)

### Stream del asistente (sin auth)
```powershell
curl -N "http://localhost:8000/api/asistente/stream?sessionId=test1&message=hola%20tengo%20un%20problema"
```

La respuesta es **Server-Sent Events (SSE)**, verás eventos como:
```
event: token
data: Hola

event: token
data: , ¿cuál

event: done
data: {"formData":{...},"mode":"recepcionista"}
```

### Nuevo usuario con IA (GET)
```powershell
curl -N "http://localhost:8000/api/asistente/nuevoUsuario?sessionId=user123&message=Me%20gustaria%20registrarme"
```

### Soporte autenticado (GET)
```powershell
curl -N "http://localhost:8000/api/asistente/soporte?sessionId=sup1&message=Tengo%20un%20problema" `
  -H "Authorization: Bearer $token"
```

---

## 7. Script completo de prueba E2E

Guarda esto como `test.ps1` y ejecuta: `.\test.ps1`

```powershell
# Variables
$baseUrl = "http://localhost:8000"
$email = "pepe@gmail.com"
$password = "123456"

Write-Host "=== 1. Health Check ===" -ForegroundColor Green
curl "$baseUrl/"

Write-Host "`n=== 2. Login ===" -ForegroundColor Green
$loginResp = curl -X POST "$baseUrl/api/auth/login" `
  -H "Content-Type: application/json" `
  -d "{`"email`":`"$email`",`"password`":`"$password`"}" | ConvertFrom-Json

$token = $loginResp.data.token
Write-Host "Token obtenido: $($token.Substring(0, 20))..."

Write-Host "`n=== 3. Get Profile ===" -ForegroundColor Green
curl "$baseUrl/api/users/me" `
  -H "Authorization: Bearer $token"

Write-Host "`n=== 4. Get My Cases ===" -ForegroundColor Green
curl "$baseUrl/api/cases/mine" `
  -H "Authorization: Bearer $token"

Write-Host "`n=== 5. List Centers ===" -ForegroundColor Green
curl "$baseUrl/api/centers"

Write-Host "`n=== 6. List Cases (all) ===" -ForegroundColor Green
curl "$baseUrl/api/cases/mine/admin" `
  -H "Authorization: Bearer $token"

Write-Host "`n✅ Testing completado" -ForegroundColor Green
```

---

## 8. Usuarios disponibles para testing

| Email | Contraseña | DNI | Rol | Uso |
|-------|-----------|-----|-----|-----|
| pepe@gmail.com | 123456 | 12345678 | consultante | Usuario normal |
| juan@gmail.com | 123456 | 98765432 | admin | Acceso admin |
| juan_facilitador@gmail.com | 123456 | 87654321 | facilitador | Asignar/resolver casos |
| maria@gmail.com | 123456 | 11111111 | consultante | Otro consultante |
| carlos@gmail.com | 123456 | 22222222 | consultante | Otro consultante |
| pedro_fac@gmail.com | 123456 | 33333333 | facilitador | Otro facilitador |
| rosa_fac@gmail.com | 123456 | 44444444 | facilitador | Otro facilitador |

---

## 9. Casos disponibles para testing

| ID | Consultante | Estado | Facilitador | Descripción |
|----|-------------|--------|-------------|-------------|
| 1 | Pepe | ingresado | — | Sin asignar (ideal para asignar) |
| 2 | María | proceso | Juan Fac | En progreso |
| 3 | Carlos | resuelto | Pedro | Resuelto |
| 4 | Pepe | cerrado | Juan Fac | Cerrado (histórico) |
| 5 | María | ingresado | — | Sin asignar |
| 6 | Carlos | asignado | Rosa Fac | Asignado |
| 7 | Pepe | proceso | Pedro | En progreso |
| 8 | María | cerrado | Juan Fac | Cerrado (antiguo) |
| 9 | Carlos | escalado | Pedro | Escalado |
| 10 | Pepe | cerrado | Rosa Fac | Cerrado (reciente) |

---

## 10. Debugging

### Ver logs del servidor
Los logs aparecen en la terminal donde levantaste el servidor. Si hay errores, mira ahí.

### Errores comunes

**"Ruta no encontrada"**
- Verifica que escribiste la URL exactamente igual
- Recuerda: `/api/problem-types` usa **guión**, no guion bajo

**"No autorizado" (401)**
- Olvidaste el header `Authorization`
- El token expiró (duraci\u00f3n: 7 días)

**"Permiso insuficiente" (403)**
- El usuario no tiene el rol requerido
- Usa un facilitador para `/api/cases/available`, por ejemplo

**CORS error**
- Normalmente no ocurre en localhost
- En producción, ajusta `CORS_ORIGIN` en `config/database.php`

---

## 11. Reiniciar base de datos

Si todo se ensucia, reimporta el schema:

```powershell
# En MySQL/MariaDB
DROP DATABASE IF EXISTS acceso_senior;
CREATE DATABASE acceso_senior CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
# Luego importa schema/acceso_senior_v2.sql
```

O desde terminal MySQL:
```bash
mysql -u root -p acceso_senior < schema/acceso_senior_v2.sql
```
