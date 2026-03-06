# Acceso Senior Backend — Referencia de API (PHP)

> Migración completa del backend Node.js/Express a PHP 8+ para hosting compartido.

---

## Tabla de contenidos

1. [Información general](#información-general)
2. [Autenticación](#autenticación)
3. [Endpoints](#endpoints)
4. [Esquema de base de datos](#esquema-de-base-de-datos)
5. [Módulos de IA](#módulos-de-ia)
6. [Despliegue](#despliegue)

---

## Información general

| Propiedad       | Valor                                  |
|-----------------|----------------------------------------|
| Runtime         | PHP 8.0+                              |
| Base de datos   | MySQL 5.7+ / MariaDB 10.x             |
| Servidor        | Apache con mod_rewrite                 |
| Dependencias    | Ninguna externa (sin Composer)         |
| Formato respuesta | `application/json; charset=utf-8`    |

### Formato de respuesta estándar

**Éxito:**
```json
{
  "success": true,
  "data": { ... }
}
```

**Error:**
```json
{
  "success": false,
  "error": "Descripción del error"
}
```

---

## Autenticación

Se utiliza **JWT (HMAC-SHA256)** generado manualmente (sin librerías externas).

- Enviar el token en la cabecera: `Authorization: Bearer <token>`
- Payload: `{ id, role, email, iat, exp }`
- Expiración por defecto: 7 días
- Los hashes bcrypt de Node.js son **compatibles** con `password_verify()` de PHP

---

## Endpoints

### Auth

| Método | URL                  | Auth | Descripción             |
|--------|----------------------|------|--------------------------|
| POST   | `/api/auth/login`    | No   | Iniciar sesión           |
| POST   | `/api/auth/register` | No   | Registrar nuevo usuario  |

#### POST `/api/auth/login`

Body:
```json
{
  "email": "pepe@gmail.com",
  "password": "123456"
}
```

Respuesta:
```json
{
  "success": true,
  "data": {
    "token": "eyJ...",
    "user": { "id": 2, "name": "Pepe", "role": "consultante", ... }
  }
}
```

#### POST `/api/auth/register`

Body:
```json
{
  "name": "Nuevo Usuario",
  "email": "nuevo@ejemplo.com",
  "password": "miPassword123",
  "phone": "1155667788",
  "dni": "12345678",
  "role": "consultante",
  "center_id": 1,
  "zone": "Caballito",
  "address": "Av. Rivadavia 5000"
}
```

---

### Cases (Consultas)

| Método | URL                           | Auth         | Rol requerido       | Descripción                  |
|--------|-------------------------------|--------------|----------------------|-------------------------------|
| POST   | `/api/cases`                  | No           | —                    | Crear nuevo caso              |
| GET    | `/api/cases/mine`             | Sí           | —                    | Mis casos (consultante)       |
| GET    | `/api/cases/mine/facilitador` | Sí           | facilitador          | Mis casos asignados           |
| GET    | `/api/cases/mine/admin`       | Sí           | admin                | Todos los casos               |
| GET    | `/api/cases/available`        | Sí           | facilitador          | Casos sin asignar             |
| POST   | `/api/cases/:id/assign`       | Sí           | facilitador          | Asignar caso a facilitador    |
| PUT    | `/api/cases/:id/status`       | Sí           | facilitador, admin   | Cambiar estado del caso       |
| GET    | `/api/cases/:id`              | Sí           | —                    | Obtener caso por ID           |

#### POST `/api/cases`

Body:
```json
{
  "consultante_id": 2,
  "center_id": 1,
  "problem_type_id": 1,
  "description": "No puedo crear una cuenta de banco",
  "input_method": "texto"
}
```

#### PUT `/api/cases/:id/status`

Body:
```json
{
  "status": "proceso"
}
```

Estados válidos: `ingresado`, `asignado`, `proceso`, `resuelto`, `cerrado`, `escalado`

Comportamientos especiales:
- `ingresado` → desasigna facilitador (`facilitator_id = NULL`)
- `resuelto` → registra `resolved_at`
- `cerrado` → registra `closed_at`

---

### Users (Usuarios)

| Método | URL              | Auth | Descripción              |
|--------|------------------|------|---------------------------|
| GET    | `/api/users/me`  | Sí   | Obtener perfil propio     |
| PUT    | `/api/users/me`  | Sí   | Actualizar perfil propio  |

#### PUT `/api/users/me`

Body (todos opcionales):
```json
{
  "name": "Nuevo Nombre",
  "phone": "1199887766",
  "dni": "12345678",
  "zone": "Palermo",
  "address": "Av. Santa Fe 3200"
}
```

---

### Centers (Centros comunitarios)

| Método | URL             | Auth | Rol requerido | Descripción        |
|--------|-----------------|------|---------------|--------------------|
| GET    | `/api/centers`  | No   | —             | Listar centros     |
| POST   | `/api/centers`  | Sí   | admin         | Crear centro       |

---

### Problem Types (Tipos de problema)

| Método | URL                   | Auth | Descripción               |
|--------|-----------------------|------|----------------------------|
| GET    | `/api/problem-types`  | No   | Listar tipos de problema   |
| POST   | `/api/problem-types`  | No   | Crear tipo de problema     |

> **Nota:** La URL usa guión (`problem-types`) para compatibilidad con el frontend original.

---

### Asistente IA

| Método | URL                            | Auth | Descripción                          |
|--------|--------------------------------|------|---------------------------------------|
| GET    | `/api/asistente/stream`        | No   | Chat SSE con IA (modo dual)          |
| GET    | `/api/asistente/soporte`       | Sí   | Chat para usuarios autenticados (WIP)|
| GET    | `/api/asistente/nuevoUsuario`  | No   | Registro asistido por IA (WIP)       |

#### GET `/api/asistente/stream`

Query params:
```
?sessionId=abc123&message=Hola%2C+tengo+un+problema
```

Respuesta: **Server-Sent Events (SSE)** — `text/event-stream`

```
event: token
data: Hola

event: token
data: , ¿en qué

event: done
data: {"formData":{...},"mode":"recepcionista"}
```

**Modos del asistente:**
1. **Recepcionista:** Identifica al usuario por DNI. Si existe, pasa a modo formulario.
2. **Formulario:** Recopila datos del problema mediante conversación guiada.

#### GET `/api/asistente/soporte`

Query params: `?sessionId=xxx&message=xxx`

#### GET `/api/asistente/nuevoUsuario`

Query params: `?sessionId=xxx&message=xxx`

---

## Esquema de base de datos

### Tablas

| Tabla          | Descripción                                    |
|----------------|------------------------------------------------|
| centers        | Centros comunitarios                           |
| users          | Usuarios (consultantes, facilitadores, admin)  |
| problem_types  | Categorías de problemas                        |
| cases          | Consultas/casos registrados                    |
| case_history   | Historial de acciones sobre cada caso          |
| ai_sessions    | Sesiones de IA (opcional, alternativa a files)  |

### Mejoras respecto al schema original

1. **Campo `dni`** en tabla `users` (VARCHAR 20) con índice
2. **UNIQUE** en `users.email` y `users.phone`
3. **Índices** en `cases.status` y `cases.created_at`
4. **`problem_type_id` nullable** en cases (IA a veces no lo asigna)
5. **Tabla `case_history`** con campos JSON para valores anteriores/nuevos
6. **Tabla `ai_sessions`** para persistencia de sesiones IA en BD

### Diagrama ER simplificado

```
centers ──┬── users
          └── cases ── case_history
                │
problem_types ──┘
```

---

## Módulos de IA

| Archivo                  | Función                                        |
|--------------------------|------------------------------------------------|
| `ia/llm_client.php`     | Cliente cURL para Groq y Gemini APIs           |
| `ia/run_agent.php`      | Orquestador: selecciona prompt, llama LLM      |
| `ia/json_extractor.php` | Extrae JSON de respuestas de texto libre        |
| `ia/session_manager.php`| Persistencia de sesiones IA (archivos JSON)     |
| `prompts/prompt_formulario.php` | Prompt para recopilación de datos        |
| `prompts/prompt_identidad.php`  | Prompt para identificación de usuario    |

### Proveedores LLM soportados

| Proveedor | API Key              | Modelo por defecto         |
|-----------|----------------------|----------------------------|
| Groq      | `GROQ_API_KEY`       | llama-3.1-8b-instant       |
| Gemini    | `GEMINI_API_KEY`     | gemini-2.0-flash-exp       |

Prioridad: Si `GROQ_API_KEY` está configurada, se usa Groq. Si no, Gemini.

---

## Despliegue

### Requisitos del hosting

- PHP 8.0 o superior
- MySQL 5.7+ o MariaDB 10.x
- Apache con `mod_rewrite` habilitado
- Extensiones PHP: `pdo`, `pdo_mysql`, `curl`, `json`, `mbstring`

### Pasos de instalación

#### 1. Subir archivos

Subir todo el contenido de la carpeta `servidorPHP/` a la carpeta pública del hosting (ej: `public_html/` o un subdirectorio).

```
public_html/
├── .htaccess
├── index.php
├── config/
├── utils/
├── middleware/
├── api/
├── ia/
├── prompts/
├── storage/
│   └── ai_sessions/     ← Crear esta carpeta con permisos de escritura
└── schema/
```

#### 2. Crear base de datos

Desde phpMyAdmin o la terminal MySQL:

```sql
CREATE DATABASE acceso_senior CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Importar el schema:

```bash
mysql -u usuario -p acceso_senior < schema/acceso_senior_v2.sql
```

O importar desde phpMyAdmin usando el archivo `schema/acceso_senior_v2.sql`.

#### 3. Configurar variables de entorno

Copiar `.env.example` como `.env` en la raíz del proyecto y completar valores reales:

```env
DB_HOST=localhost
DB_NAME=acceso_senior
DB_USER=tu_usuario_mysql
DB_PASS=tu_contrasena_mysql
DB_CHARSET=utf8mb4

JWT_SECRET=secreto_largo_y_aleatorio
JWT_EXPIRATION=604800

CORS_ORIGIN=https://tu-frontend.com

GROQ_API_KEY=
GEMINI_API_KEY=
```

Notas:
- En producción, evitar `CORS_ORIGIN=*`.
- No commitear nunca `.env` (ya está excluido en `.gitignore`).

#### 4. Permisos de carpetas

```bash
chmod 755 storage/
chmod 755 storage/ai_sessions/
```

#### 5. Verificar mod_rewrite

Verificar que Apache tiene `mod_rewrite` habilitado y que `AllowOverride All` está configurado para el directorio.

#### 6. Probar

```bash
# Raíz
curl https://tu-dominio.com/api/ 
# → {"ok":true,"service":"Acceso Senior Backend (PHP)"}

# Login
curl -X POST https://tu-dominio.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"pepe@gmail.com","password":"123456"}'

# Centros (sin auth)
curl https://tu-dominio.com/api/centers
```

---

## Estructura de archivos

```
servidorPHP/
├── .htaccess                    # Rewrite rules → index.php
├── index.php                    # Router principal
├── config/
│   ├── database.php             # Conexión PDO + constantes
│   └── cors.php                 # Manejo CORS
├── utils/
│   ├── jwt.php                  # JWT HMAC-SHA256 manual
│   ├── response.php             # Helpers JSON response
│   └── validation.php           # Validaciones de datos
├── middleware/
│   ├── auth.php                 # authRequired()
│   └── role.php                 # requireRole()
├── api/
│   ├── auth/
│   │   ├── login.php
│   │   └── register.php
│   ├── cases/
│   │   ├── assign.php
│   │   ├── create.php
│   │   ├── get_available.php
│   │   ├── get_by_id.php
│   │   ├── get_mine.php
│   │   ├── get_mine_admin.php
│   │   ├── get_mine_facilitador.php
│   │   └── update_status.php
│   ├── users/
│   │   ├── get_profile.php
│   │   └── update_profile.php
│   ├── centers/
│   │   ├── list.php
│   │   └── create.php
│   ├── problem_types/
│   │   ├── list.php
│   │   └── create.php
│   └── asistente/
│       ├── stream.php           # SSE streaming (dual-mode)
│       ├── soporte.php          # Soporte autenticado (WIP)
│       └── nuevo_usuario.php    # Registro IA (WIP)
├── ia/
│   ├── json_extractor.php
│   ├── llm_client.php
│   ├── run_agent.php
│   └── session_manager.php
├── prompts/
│   ├── prompt_formulario.php
│   └── prompt_identidad.php
├── schema/
│   └── acceso_senior_v2.sql
└── storage/
    └── ai_sessions/             # Sesiones IA (archivos JSON)
        └── .htaccess            # Deny from all
```
