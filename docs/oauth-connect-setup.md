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

---

## 1. Google (cubre GA4 + Search Console + Google Ads con un solo login)

### 1.1 Crear la app OAuth
1. Entra en **Google Cloud Console** → crea (o elige) un proyecto.
2. **APIs y servicios → Biblioteca**: habilita estas APIs según lo que quieras ofrecer:
   - **Google Analytics Data API** y **Google Analytics Admin API** (para GA4 + el selector de propiedad).
   - **Google Search Console API** (para GSC).
   - **Google Ads API** (para Google Ads).
3. **APIs y servicios → Pantalla de consentimiento de OAuth**:
   - Tipo de usuario: **Externo**.
   - Rellena nombre de la app, logo, dominio y correos de soporte.
   - **Scopes**: añade solo los de lectura que uses:
     - `.../auth/analytics.readonly`
     - `.../auth/webmasters.readonly`
     - `.../auth/adwords`
4. **APIs y servicios → Credenciales → Crear credenciales → ID de cliente de OAuth**:
   - Tipo: **Aplicación web**.
   - **URIs de redirección autorizados**: añade los tres de la tabla de arriba (ga4, gsc, google_ads).
   - Copia el **Client ID** y el **Client secret**.

### 1.2 Verificación de la app (importante)
Con scopes "sensibles" y usuarios externos, Google exige **verificación** de la app (revisión con
video, dominio verificado y política de privacidad). Mientras esté sin verificar:
- Puedes añadir **usuarios de prueba** en la pantalla de consentimiento y conectar solo con esos.
- Los usuarios externos verán una advertencia "app no verificada".

Para producción con clientes reales, completa la verificación de Google.

### 1.3 Google Ads: developer token
Google Ads necesita además un **developer token** (API Center de tu cuenta de Google Ads) con
**acceso básico** aprobado. Si accedes a las cuentas de clientes a través de una cuenta
administradora (MCC), anota también su ID.

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
   `https://reports.imagina.cloud/api/v1/connect/callback/facebook_ads`.
5. **App Review**: solicita el permiso **`ads_read`**. Meta exige **verificación de negocio** y
   una revisión (screencast del flujo). Mientras tanto, los **roles de la app** (tú y tus testers)
   pueden conectar en modo desarrollo.

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
