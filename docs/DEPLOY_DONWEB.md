# Deploy DonWeb + Frontend Localhost

Guia operativa para publicar el backend PHP en DonWeb y consumirlo desde frontend en localhost.

## 1. Estructura a subir

Subir todo el contenido de `ServidorPHP/` al directorio publicado por el hosting.

Archivos clave:
- `.htaccess`
- `index.php`
- `config/`
- `api/`
- `middleware/`
- `utils/`
- `ia/`
- `prompts/`
- `storage/`

## 2. Variables de entorno backend (.env)

En el servidor, crear/actualizar `.env` con este formato:

```env
DB_HOST=localhost
DB_NAME=TU_DB
DB_USER=TU_USER
DB_PASS=TU_PASS
JWT_SECRET=CAMBIAR_POR_UN_SECRETO_LARGO
JWT_EXPIRATION=604800

# Permite front local y front productivo
CORS_ORIGIN=http://localhost:3000,https://tu-frontend.com
CORS_ALLOW_CREDENTIALS=false

# IA (opcional)
GROQ_API_KEY=
GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.5-flash-lite

LLM_TIMEOUT=120
LLM_CONNECT_TIMEOUT=45
LLM_MAX_RETRIES=3

# Si DonWeb protege URL con Basic Auth
PROXY_USER=TU_USER_BASIC
PROXY_PASSWORD=TU_PASS_BASIC
```

Notas:
- Si solo usaras frontend local durante pruebas, puedes dejar `CORS_ORIGIN=http://localhost:3000`.
- `CORS_ALLOW_CREDENTIALS=false` es correcto cuando usas JWT por headers.

## 3. Autenticacion Basica de DonWeb

Si DonWeb protege el acceso con Basic Auth:
- Desde navegador directo puede fallar preflight (`OPTIONS`) con `401` antes de llegar a PHP.
- Recomendado en desarrollo local: usar proxy de React (`form/src/setupProxy.js`).
- El proxy agrega `Authorization: Basic ...` hacia DonWeb y el JWT viaja por `X-Authorization`.

## 4. Variables de entorno frontend

En `form/.env.local` (crear en tu maquina local):

```env
REACT_APP_API_URL=/api
REACT_APP_USE_BASIC_AUTH=false
REACT_APP_USE_X_AUTHORIZATION=true
BACKEND_PROXY_TARGET=https://c2371280.ferozo.com/api
BACKEND_BASIC_AUTH_USER=TU_USER_BASIC
BACKEND_BASIC_AUTH_PASS=TU_PASS_BASIC
```

Luego reiniciar el frontend:

```powershell
npm start
```

## 5. Pruebas tecnicas minimas

### 5.1 Preflight CORS

```powershell
curl -i -X OPTIONS "https://c2371280.ferozo.com/api/auth/login" ^
  -H "Origin: http://localhost:3000" ^
  -H "Access-Control-Request-Method: POST" ^
  -H "Access-Control-Request-Headers: authorization,x-authorization,content-type"
```

Esperado:
- Status `204` (o `200`)
- `Access-Control-Allow-Origin: http://localhost:3000`
- `Access-Control-Allow-Headers` incluyendo `Authorization` y `X-Authorization`

Si devuelve `401 Unauthorized`, el bloqueo ocurre en la capa Basic Auth del hosting y no en tu PHP. En ese caso usa el proxy local como ruta principal de desarrollo.

### 5.2 Login desde frontend

- Abrir `http://localhost:3000/login`
- Iniciar sesion
- Verificar en DevTools que:
  - Request tenga `Authorization: Basic ...`
  - Request tenga `X-Authorization: Bearer ...` en llamadas protegidas

## 6. Diagnostico rapido de fallas

- Error CORS en navegador:
  - Revisar que el origen local este en `CORS_ORIGIN`
  - Verificar que `OPTIONS` no quede bloqueado por reglas externas

- Error 401 antes de llegar a PHP:
  - Es challenge de Basic Auth del hosting
  - Confirmar credenciales Basic del frontend

- Login correcto pero endpoints protegidos fallan con "Token requerido":
  - Confirmar `REACT_APP_USE_X_AUTHORIZATION=true`
  - Revisar request header `X-Authorization`

## 7. Checklist final de salida

- Backend subido con `.htaccess` activo
- `.env` en servidor con DB/JWT/CORS correctos
- Front local con `.env.local` correcto
- Preflight responde bien
- Login funciona
- Endpoints con JWT funcionan
