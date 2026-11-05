import { Check, Copy, ExternalLink, TriangleAlert } from 'lucide-react';
import { type ReactElement, type ReactNode, useState } from 'react';

import type { PlatformIntegrationsSettings } from '../types';

type Setup = PlatformIntegrationsSettings['setup'];

const GOOGLE_SCOPES = [
    'https://www.googleapis.com/auth/analytics.readonly',
    'https://www.googleapis.com/auth/webmasters.readonly',
    'https://www.googleapis.com/auth/adwords',
];

/** What to paste in Google's verification form, one per scope (Google reviews in English). */
const GOOGLE_JUSTIFICATIONS: { scope: string; text: string }[] = [
    {
        scope: 'analytics.readonly',
        text: 'Our app generates monthly performance reports that a web agency sends to its clients. We read aggregated Google Analytics 4 metrics (sessions, users, conversions, traffic sources, top pages) for the single property the user selects, to display them in the client report. We never modify the account.',
    },
    {
        scope: 'webmasters.readonly',
        text: 'We read aggregated Search Console performance data (clicks, impressions, CTR, average position, top queries and pages) for the site the user selects, to show search visibility in the monthly client report. Read-only; we never change any setting.',
    },
    {
        scope: 'adwords',
        text: 'We read Google Ads reporting data (impressions, clicks, cost, conversions, top campaigns) for the account the user selects, to show advertising results in the monthly client report. We only call reporting endpoints and never create or modify campaigns. Google offers no narrower read-only scope for the Google Ads API.',
    },
];

function CopyValue({ value }: { value: string }): ReactElement {
    const [copied, setCopied] = useState(false);

    return (
        <span className="ir-flex ir-items-center ir-gap-1.5">
            <code className="ir-min-w-0 ir-flex-1 ir-break-all ir-rounded ir-border ir-bg-muted ir-px-2 ir-py-1 ir-text-xs">{value}</code>
            <button
                type="button"
                title="Copiar"
                className="ir-shrink-0 ir-rounded ir-p-1 ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground"
                onClick={() => {
                    void navigator.clipboard.writeText(value).then(() => {
                        setCopied(true);
                        window.setTimeout(() => setCopied(false), 1500);
                    });
                }}
            >
                {copied ? <Check className="ir-size-3.5" /> : <Copy className="ir-size-3.5" />}
            </button>
        </span>
    );
}

function Link({ href, children }: { href: string; children: ReactNode }): ReactElement {
    return (
        <a href={href} target="_blank" rel="noreferrer" className="ir-inline-flex ir-items-center ir-gap-0.5 ir-font-medium ir-text-foreground ir-underline">
            {children}
            <ExternalLink className="ir-size-3" />
        </a>
    );
}

function Step({ n, title, children }: { n: number; title: string; children: ReactNode }): ReactElement {
    return (
        <li className="ir-flex ir-gap-3">
            <span className="ir-flex ir-size-6 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-full ir-bg-muted ir-text-xs ir-font-semibold">{n}</span>
            <div className="ir-flex ir-min-w-0 ir-flex-1 ir-flex-col ir-gap-1.5 ir-text-sm">
                <p className="ir-font-medium">{title}</p>
                <div className="ir-flex ir-flex-col ir-gap-1.5 ir-text-muted-foreground">{children}</div>
            </div>
        </li>
    );
}

function Labeled({ label, value }: { label: string; value: string }): ReactElement {
    return (
        <div className="ir-flex ir-flex-col ir-gap-0.5">
            <span className="ir-text-xs">{label}</span>
            <CopyValue value={value} />
        </div>
    );
}

function Guide({ title, children }: { title: string; children: ReactNode }): ReactElement {
    return (
        <details className="ir-rounded-md ir-border ir-bg-background">
            <summary className="ir-cursor-pointer ir-select-none ir-px-3 ir-py-2 ir-text-sm ir-font-medium">{title}</summary>
            <div className="ir-border-t ir-px-3 ir-py-4">{children}</div>
        </details>
    );
}

function HttpsWarning({ setup }: { setup: Setup }): ReactElement | null {
    if (setup.https) {
        return null;
    }

    return (
        <p className="ir-mb-3 ir-flex ir-gap-2 ir-rounded-md ir-bg-warning/10 ir-p-2.5 ir-text-xs ir-text-warning">
            <TriangleAlert className="ir-size-4 ir-shrink-0" />
            La app está configurada con {setup.app_url} (sin https). Google y Meta exigen https: pon en APP_URL la dirección pública con https
            antes de registrar estas URLs.
        </p>
    );
}

/**
 * The full Google Cloud setup, in order, with this installation's real URLs to copy. Lives in
 * the panel (not only in a repo doc) so nothing is left out: APIs, branding, publishing,
 * scopes, client, domain verification and the verification request.
 */
export function GoogleSetupGuide({ setup }: { setup: Setup }): ReactElement {
    return (
        <Guide title="Guía paso a paso para configurar Google (incluye publicar y verificar la app)">
            <HttpsWarning setup={setup} />
            <ol className="ir-flex ir-flex-col ir-gap-5">
                <Step n={1} title="Crea o elige un proyecto">
                    <p>
                        En <Link href="https://console.cloud.google.com/">Google Cloud Console</Link>, arriba a la izquierda, elige el proyecto que
                        usarás para Imagina Reports (o crea uno). Todo lo siguiente se hace dentro de ese mismo proyecto.
                    </p>
                </Step>

                <Step n={2} title="Activa las APIs">
                    <p>Abre cada enlace (con el proyecto elegido) y pulsa «Habilitar»:</p>
                    <ul className="ir-list-disc ir-pl-5">
                        <li><Link href="https://console.cloud.google.com/apis/library/analyticsdata.googleapis.com">Google Analytics Data API</Link> — métricas de GA4.</li>
                        <li><Link href="https://console.cloud.google.com/apis/library/analyticsadmin.googleapis.com">Google Analytics Admin API</Link> — lista de propiedades para el desplegable.</li>
                        <li><Link href="https://console.cloud.google.com/apis/library/searchconsole.googleapis.com">Google Search Console API</Link>.</li>
                        <li><Link href="https://console.cloud.google.com/apis/library/googleads.googleapis.com">Google Ads API</Link> — solo si ofreces Google Ads.</li>
                    </ul>
                    <p>Si falta alguna, la conexión se completa pero «Probar» o el escaneo de cuentas fallan.</p>
                </Step>

                <Step n={3} title="Branding (Google Auth Platform → Branding)">
                    <p>Rellena estos campos con las páginas públicas que ya tiene la app:</p>
                    <Labeled label="Página principal de la aplicación" value={setup.homepage_url} />
                    <Labeled label="Política de privacidad" value={setup.privacy_url} />
                    <Labeled label="Condiciones del servicio" value={setup.terms_url} />
                    <Labeled label="Dominio autorizado (usa el dominio principal, sin subdominio si es uno)" value={setup.domain} />
                    <p>
                        Pon también el nombre de la app, el email de asistencia y el email de contacto del desarrollador. El logo es opcional: si lo
                        subes, Google también tiene que revisarlo.
                    </p>
                </Step>

                <Step n={4} title="Audience: usuarios externos y app publicada">
                    <p>
                        En <strong>Audience</strong>, el tipo de usuario debe ser <strong>External</strong>. En «Publishing status», si dice
                        <strong> Testing</strong>, pulsa <strong>«Publish app»</strong> para pasarla a <strong>In production</strong>.
                    </p>
                    <p>
                        En modo Testing solo pueden conectar los usuarios de prueba que añadas, y Google <strong>caduca las conexiones a los 7 días</strong>:
                        las fuentes de tus clientes se desconectarían cada semana.
                    </p>
                </Step>

                <Step n={5} title="Data Access: los permisos">
                    <p>Pulsa «Add or remove scopes» y añade estos tres (si alguno no aparece en el buscador, pégalo en «Manually add scopes»):</p>
                    {GOOGLE_SCOPES.map((scope) => (
                        <CopyValue key={scope} value={scope} />
                    ))}
                    <p>Pulsa «Update» y luego «Save». El de Google Ads solo hace falta si lo ofreces.</p>
                </Step>

                <Step n={6} title="Clients: el cliente OAuth">
                    <p>
                        <strong>Create client</strong> → tipo <strong>Web application</strong>. En «Authorized redirect URIs» añade estas tres URLs,
                        exactamente así:
                    </p>
                    {setup.google_redirect_uris.map((uri) => (
                        <CopyValue key={uri} value={uri} />
                    ))}
                    <p>Copia el <strong>Client ID</strong> y el <strong>Client secret</strong> y pégalos arriba, en esta tarjeta. Pulsa «Guardar Google».</p>
                </Step>

                <Step n={7} title="Verifica el dominio">
                    <p>
                        En <Link href="https://search.google.com/search-console">Google Search Console</Link>, con la misma cuenta de Google que es
                        propietaria del proyecto, añade y verifica el dominio <strong>{setup.domain}</strong>. Google no aprueba la app sin esto.
                    </p>
                </Step>

                <Step n={8} title="Solicita la verificación (Verification Center)">
                    <p>Esto es lo que quita el aviso «Google no ha verificado esta aplicación». Google pide:</p>
                    <ul className="ir-list-disc ir-pl-5">
                        <li>Una justificación por permiso. Puedes copiar estas (Google revisa en inglés):</li>
                    </ul>
                    {GOOGLE_JUSTIFICATIONS.map((item) => (
                        <Labeled key={item.scope} label={item.scope} value={item.text} />
                    ))}
                    <ul className="ir-list-disc ir-pl-5">
                        <li>
                            Un vídeo en YouTube (puede ser «no listado») que muestre: pulsar «Conectar con Google» en el panel, la pantalla de consentimiento
                            de Google con la barra de direcciones visible (se ve el client_id) y dónde aparecen esos datos en un reporte.
                        </li>
                        <li>Google responde por email. Suele tardar de unos días a algunas semanas y a veces pide aclaraciones.</li>
                    </ul>
                </Step>

                <Step n={9} title="Mientras Google revisa">
                    <p>
                        La conexión ya funciona, pero quien conecte verá el aviso de app no verificada y tendrá que pulsar
                        «Configuración avanzada» → «Ir a … (no seguro)». Mientras tanto, Google limita a 100 los usuarios nuevos.
                    </p>
                </Step>

                <Step n={10} title="Prueba la conexión">
                    <p>
                        En Clientes → un sitio → Fuentes de datos → añade GA4 → «Conectar con Google». Al volver, la app te dice qué propiedad eligió o te
                        muestra el desplegable. Si algo falla, el mensaje indica la causa exacta (permiso sin marcar, URL de retorno sin registrar…).
                    </p>
                </Step>
            </ol>
        </Guide>
    );
}

/** Google Ads' own extra steps: the developer token and its Basic Access approval. */
export function GoogleAdsSetupGuide(): ReactElement {
    return (
        <Guide title="Cómo conseguir el developer token de Google Ads">
            <ol className="ir-flex ir-flex-col ir-gap-5">
                <Step n={1} title="Cuenta de administrador (MCC)">
                    <p>
                        Necesitas una <Link href="https://ads.google.com/home/tools/manager-accounts/">cuenta de administrador de Google Ads</Link> (es
                        gratis). Entra en ella → Herramientas → Configuración → <strong>Centro de API</strong>.
                    </p>
                </Step>
                <Step n={2} title="Copia el token y pide «Acceso básico»">
                    <p>
                        Copia el developer token y pégalo arriba. Un token nuevo solo funciona con cuentas de prueba: en el mismo Centro de API pide el
                        <strong> acceso básico</strong> (describe la app como «reportes de solo lectura para los clientes de una agencia»). Google suele
                        aprobarlo en pocos días hábiles.
                    </p>
                </Step>
                <Step n={3} title="Botón de conexión">
                    <p>
                        El botón «Conectar con Google» para Google Ads solo aparece cuando hay un developer token guardado. El Login Customer ID solo hace
                        falta si las cuentas de tus clientes cuelgan de tu MCC.
                    </p>
                </Step>
            </ol>
        </Guide>
    );
}

/** Meta's equivalent: one app for Facebook Ads + Instagram. */
export function MetaSetupGuide({ setup }: { setup: Setup }): ReactElement {
    return (
        <Guide title="Guía paso a paso para configurar Meta (incluye revisión y modo activo)">
            <HttpsWarning setup={setup} />
            <ol className="ir-flex ir-flex-col ir-gap-5">
                <Step n={1} title="Crea la app">
                    <p>
                        En <Link href="https://developers.facebook.com/apps/">Meta for Developers</Link> crea una app de tipo <strong>Empresa</strong> (Business)
                        y vincúlala a tu portfolio comercial.
                    </p>
                </Step>
                <Step n={2} title="Datos básicos (Configuración de la app → Básica)">
                    <Labeled label="Dominios de la app" value={setup.domain} />
                    <Labeled label="URL de la política de privacidad" value={setup.privacy_url} />
                    <Labeled label="URL de las condiciones del servicio" value={setup.terms_url} />
                    <p>Añade también el icono y la categoría. Copia el <strong>App ID</strong> y la <strong>clave secreta</strong> y pégalos arriba.</p>
                </Step>
                <Step n={3} title="Inicio de sesión con Facebook: URLs de retorno">
                    <p>Añade el producto de inicio de sesión con Facebook y, en su configuración, en «URI de redireccionamiento de OAuth válidos», pega:</p>
                    {setup.meta_redirect_uris.map((uri) => (
                        <CopyValue key={uri} value={uri} />
                    ))}
                </Step>
                <Step n={4} title="Permisos y revisión de la app">
                    <p>Solicita acceso avanzado a estos permisos en «Revisión de la app»:</p>
                    <ul className="ir-list-disc ir-pl-5">
                        <li>Facebook Ads: <code>ads_read</code>, <code>business_management</code>.</li>
                        <li>
                            Instagram: <code>instagram_basic</code>, <code>instagram_manage_insights</code>, <code>pages_show_list</code>,{' '}
                            <code>pages_read_engagement</code>, <code>business_management</code>.
                        </li>
                    </ul>
                    <p>Meta exige la <strong>verificación del negocio</strong> y un vídeo del flujo para aprobarlos.</p>
                </Step>
                <Step n={5} title="Activa la app">
                    <p>
                        Cambia el modo de la app de «Desarrollo» a <strong>«Activo»</strong>. Hasta que la revisión esté aprobada y la app activa, solo pueden
                        conectar las personas que tengan un rol en la app (tú y tus testers).
                    </p>
                </Step>
            </ol>
        </Guide>
    );
}

/**
 * MercadoPago's notifications: the URL it must call, the events, and where the «firma» (the
 * secret signing key) comes from. Without the URL MercadoPago never tells us about a renewal or a
 * cancellation, and the app only catches up once a day.
 */
export function MercadoPagoWebhookGuide({ url, open = false }: { url: string; open?: boolean }): ReactElement {
    return (
        <details className="ir-rounded-md ir-border ir-bg-background" open={open}>
            <summary className="ir-cursor-pointer ir-select-none ir-px-3 ir-py-2 ir-text-sm ir-font-medium">Cómo configurar los avisos (webhook) y la firma</summary>
            <div className="ir-flex ir-flex-col ir-gap-4 ir-border-t ir-px-3 ir-py-4">
                <p className="ir-text-sm ir-text-muted-foreground">
                    <strong className="ir-font-medium ir-text-foreground">Qué es esto:</strong> MercadoPago avisa a la plataforma cada vez que una agencia se
                    suscribe, renueva, falla un cobro o cancela. La <strong className="ir-font-medium ir-text-foreground">firma</strong> es una clave secreta
                    que MercadoPago pone en cada aviso para demostrar que viene de verdad de ellos y no de otra persona.
                </p>
                <ol className="ir-flex ir-flex-col ir-gap-5">
                    <Step n={1} title="Abre los webhooks de tu aplicación">
                        <p>
                            Entra en <Link href="https://www.mercadopago.com/developers/panel/app">MercadoPago Developers → Tus integraciones</Link>, abre la
                            aplicación de la que sacaste el Access Token y ve a <strong>Webhooks → Configurar notificaciones</strong>.
                        </p>
                    </Step>
                    <Step n={2} title="Pega esta URL">
                        <CopyValue value={url} />
                        <p>
                            Ponla en <strong>Modo productivo</strong>. Si estás probando con un token <code>TEST-…</code>, ponla también en <strong>Modo de
                            prueba</strong>.
                        </p>
                    </Step>
                    <Step n={3} title="Elige los eventos">
                        <p>
                            Marca <strong>Planes y suscripciones</strong> (incluye las suscripciones y sus cobros mensuales). No hace falta ningún otro.
                        </p>
                    </Step>
                    <Step n={4} title="Guarda y copia la clave secreta">
                        <p>
                            Al guardar, MercadoPago muestra una <strong>clave secreta</strong> para ese webhook (puede que tengas que pulsar «Revelar»). Cópiala y
                            pégala arriba, en «Clave secreta del webhook», y pulsa «Guardar cambios». El indicador «Firma de avisos» pasará a «listo».
                        </p>
                    </Step>
                </ol>
                <p className="ir-rounded-md ir-bg-muted/60 ir-px-3 ir-py-2 ir-text-xs ir-leading-relaxed ir-text-muted-foreground">
                    <strong className="ir-font-medium ir-text-foreground">¿Y si no pongo la clave?</strong> Los cobros siguen siendo seguros: la plataforma nunca se
                    fía del contenido del aviso y siempre le pregunta el estado a MercadoPago. La clave añade una capa más y frena tráfico falso. Lo
                    importante es el paso 2: sin la URL, MercadoPago no avisa y las altas, renovaciones y cancelaciones se reflejan una vez al día (a las
                    03:50), no al momento.
                </p>
            </div>
        </details>
    );
}

/** PayPal's notifications: the URL, the exact events the app handles, and the Webhook ID. */
export function PayPalWebhookGuide({ url }: { url: string }): ReactElement {
    const events = [
        'BILLING.SUBSCRIPTION.ACTIVATED',
        'BILLING.SUBSCRIPTION.RE-ACTIVATED',
        'BILLING.SUBSCRIPTION.SUSPENDED',
        'BILLING.SUBSCRIPTION.CANCELLED',
        'BILLING.SUBSCRIPTION.EXPIRED',
        'BILLING.SUBSCRIPTION.PAYMENT.FAILED',
        'PAYMENT.SALE.COMPLETED',
    ];

    return (
        <Guide title="Cómo configurar el webhook de PayPal">
            <ol className="ir-flex ir-flex-col ir-gap-5">
                <Step n={1} title="Abre tu app REST">
                    <p>
                        En <Link href="https://developer.paypal.com/dashboard/applications">PayPal Developer → Apps & Credentials</Link>, abre la app de la que
                        sacaste el Client ID (en la pestaña Sandbox o Live, según el modo) y baja hasta <strong>Webhooks → Add Webhook</strong>.
                    </p>
                </Step>
                <Step n={2} title="Pega esta URL">
                    <CopyValue value={url} />
                </Step>
                <Step n={3} title="Marca estos eventos">
                    <ul className="ir-flex ir-flex-col ir-gap-0.5 ir-font-mono ir-text-xs">
                        {events.map((event) => (
                            <li key={event}>{event}</li>
                        ))}
                    </ul>
                </Step>
                <Step n={4} title="Copia el Webhook ID">
                    <p>Al guardar, PayPal muestra el <strong>Webhook ID</strong> (empieza por letras y números, p. ej. 5GP…). Pégalo arriba y guarda.</p>
                </Step>
            </ol>
        </Guide>
    );
}
