# Conexión de un clic — configuración de Google y Meta

Esta guía es para **el operador de la plataforma** (tú, Imagina). Se hace **una sola vez**.
Después, cada cliente conecta su cuenta pulsando un botón, sin pegar JSON ni tokens.

> La app ya trae todo el código. Lo único que falta es que **registres una app OAuth** en
> Google y otra en Meta, y pongas sus credenciales en el `.env`. Mientras no lo hagas, el
> botón "Conectar con…" no aparece y sigue funcionando el formulario manual de siempre.

Los **redirect URIs** (URLs de retorno) que registrarás apuntan a estos endpoints públicos
(sustituye `https://reports.imagina.cloud` por tu `APP_URL`):

| Fuente | Redirect URI a registrar |
|---|---|
| Google Analytics (GA4) | `https://reports.imagina.cloud/api/v1/connect/callback/ga4` |
| Search Console (GSC) | `https://reports.imagina.cloud/api/v1/connect/callback/gsc` |
| Google Ads | `https://reports.imagina.cloud/api/v1/connect/callback/google_ads` |
| Facebook / Instagram Ads | `https://reports.imagina.cloud/api/v1/connect/callback/facebook_ads` |
| Instagram (perfil/insights) | `https://reports.imagina.cloud/api/v1/connect/callback/instagram` |

---

## 1. Google (cubre GA4 + Search Console + Google Ads con un solo login)

> **Nota sobre la consola nueva (2025):** Google renombró la pantalla de consentimiento a
> **"Google Auth Platform"** y repartió las opciones en el menú izquierdo. El mapa es:
>
> | Lo que necesitas | Dónde está ahora |
> |---|---|
> | Tipo de usuario (Externo) + usuarios de prueba + publicar | **Audience** |
> | Nombre / logo / dominio de la app | **Branding** |
> | **Scopes** (permisos) | **Data Access** → botón *"Add or remove scopes"* |
> | **Client ID/Secret** y **redirect URIs** | **Clients** → tu cliente OAuth |

### 1.1 Crear la app OAuth
1. Entra en **Google Cloud Console** → crea (o elige) un proyecto.
2. **APIs y servicios → Biblioteca**: habilita estas APIs según lo que quieras ofrecer:
   - **Google Analytics Data API** y **Google Analytics Admin API** (para GA4 + el selector de propiedad).
   - **Google Search Console API** (para GSC).
   - **Google Ads API** (para Google Ads).
3. **Google Auth Platform → Audience**: tipo de usuario **External**. Mientras la app no esté
   verificada, añade tu propio correo como **usuario de prueba** para poder conectar.
4. **Google Auth Platform → Branding**: rellena nombre de la app, logo y dominio.
5. **Google Auth Platform → Data Access → "Add or remove scopes"**: añade solo los de lectura que
   uses. Analytics y Search Console aparecen al buscar; **el de Google Ads normalmente NO aparece
   en el buscador**, así que pégalo en la caja *"Manually add scopes"*:
   - `https://www.googleapis.com/auth/analytics.readonly`
   - `https://www.googleapis.com/auth/webmasters.readonly`
   - `https://www.googleapis.com/auth/adwords`
   - Luego **Update → Save**.
6. **Google Auth Platform → Clients → Create client**: tipo **Web application**.
   - En **Authorized redirect URIs** añade los de la tabla de arriba (ga4, gsc, google_ads).
   - Copia el **Client ID** y el **Client secret**.

### 1.2 Verificación de la app (importante)
Con scopes "sensibles" y usuarios externos, Google exige **verificación** de la app (revisión con
video, dominio verificado y política de privacidad). Mientras esté sin verificar:
- Puedes añadir **usuarios de prueba** en **Audience** y conectar solo con esos.
- Los usuarios externos verán una advertencia "app no verificada".

Para producción con clientes reales, completa la verificación de Google (**Verification Center**).

### 1.3 Google Ads (developer token) — tutorial completo
Google Ads reutiliza la **misma app OAuth** de arriba (solo asegúrate de tener el scope `adwords`
y el redirect URI `.../callback/google_ads`). Lo específico es el **developer token**, que es
**tuyo** (de la herramienta), no del cliente:

1. Si no tienes una, crea una **cuenta de administrador de Google Ads (MCC)** en
   `ads.google.com/home/tools/manager-accounts` (gratis; solo agrupa cuentas).
2. En esa MCC → **Herramientas y configuración (⚙️) → Configuración → API Center**.
3. Copia el **developer token** que aparece ahí.
4. **⚠️ Solicita "Acceso básico" (Basic Access):** un token nuevo solo sirve para **cuentas de
   prueba**. En el mismo API Center hay un formulario para pedir acceso básico (describe tu
   herramienta: reporting de solo lectura para clientes de una agencia). Google lo revisa en
   **1–3 días hábiles**. Hasta que lo aprueben, conectar cuentas reales da error de permisos.
5. **¿login_customer_id?** Dos formas de acceder a las cuentas de los clientes:
   - **Simple (recomendada):** el cliente autoriza con **su propio Google** (que ya accede a su
     cuenta de Ads). Deja `GOOGLE_ADS_LOGIN_CUSTOMER_ID` **vacío**.
   - **Bajo tu MCC:** si vinculas las cuentas de clientes bajo tu MCC, pon el **ID de tu MCC**
     (10 dígitos, sin guiones) en `GOOGLE_ADS_LOGIN_CUSTOMER_ID`.

   El developer token es **siempre el tuyo**, en ambos casos.

**Errores típicos:**
- `DEVELOPER_TOKEN_NOT_APPROVED` / permiso denegado en cuentas reales → aún tienes solo "Test
  Access"; falta que aprueben el Basic Access (paso 4).
- `USER_PERMISSION_DENIED` → el Google que autorizó el cliente no tiene acceso a esa cuenta, o
  falta el `login_customer_id` correcto si vas por MCC.
- El botón "Conectar con Google" no aparece en Google Ads → falta `GOOGLE_ADS_DEVELOPER_TOKEN` o
  las variables OAuth en el `.env` (recuerda `php artisan config:cache`).

### 1.4 Variables de entorno
```dotenv
GOOGLE_OAUTH_CLIENT_ID=xxxxx.apps.googleusercontent.com
GOOGLE_OAUTH_CLIENT_SECRET=xxxxx
# Solo si ofreces Google Ads:
GOOGLE_ADS_DEVELOPER_TOKEN=xxxxx
GOOGLE_ADS_LOGIN_CUSTOMER_ID=1234567890   # opcional (MCC), sin guiones
```

---

## 2. Meta (Facebook / Instagram Ads)

1. En **developers.facebook.com** crea una app de tipo **Empresa (Business)**.
2. Añade el producto **Marketing API**.
3. **Configuración → Básica**: copia el **App ID** y el **App Secret**.
4. **Facebook Login → Configuración → URIs de redireccionamiento de OAuth válidos**: añade
   los dos redirect URIs de Meta de la tabla (facebook_ads **e** instagram).
5. **App Review**: solicita los permisos que uses. Meta exige **verificación de negocio** y una
   revisión (screencast del flujo). Mientras tanto, los **roles de la app** (tú y tus testers)
   pueden conectar en modo desarrollo.
   - **Facebook/Instagram Ads:** `ads_read`.
   - **Instagram (perfil/insights):** `instagram_basic`, `instagram_manage_insights`,
     `pages_show_list`, `pages_read_engagement`. La cuenta de Instagram debe ser **Business o
     Creator** y estar **vinculada a una página de Facebook** (así aparece en el selector).

### 2.1 Variables de entorno
```dotenv
META_OAUTH_APP_ID=xxxxx
META_OAUTH_APP_SECRET=xxxxx
```

---

## 3. Desplegar y probar

1. Pon las variables en el `.env` del servidor y ejecuta `php artisan config:cache`
   (el flujo de Update lo hace solo al actualizar).
2. En el panel, al **añadir una fuente** GA4 / GSC / Google Ads / Facebook Ads verás el botón
   **"Conectar con Google / Facebook"**. Debajo queda el enlace *"usar mis propios accesos"*
   con el formulario manual de siempre.
3. Flujo del cliente:
   - Pulsa el botón → autoriza en la pantalla de Google/Meta (acceso de **solo lectura**).
   - Vuelve al panel; si su cuenta tiene varias propiedades/cuentas, elige la suya en el
     desplegable **"Elige tu propiedad/cuenta"** (si solo hay una, se selecciona sola).
   - Pulsa **Probar** para confirmar el estado *ok* y ya sincroniza.

## 4. Cómo funciona por dentro (referencia)

- `start` (autenticado) guarda un **intent** de un solo uso (nonce, TTL 15 min) y devuelve la
  URL de consentimiento del proveedor.
- El proveedor redirige al **callback público** (`/connect/callback/{type}`) con un `code`;
  lo canjeamos por un **refresh token** (Google) o **token de larga duración** (Meta), que se
  guarda **cifrado** como credencial de la fuente. La app OAuth (client_id/secret) es tuya y
  vive en el `.env`, nunca por cliente.
- Tras conectar, listamos las propiedades/cuentas accesibles del cliente para el selector.
- Los conectores GA4/GSC aceptan **tanto** el token OAuth como el JSON de cuenta de servicio;
  Google Ads y Meta usan el token guardado. Nada del modo manual cambió.
