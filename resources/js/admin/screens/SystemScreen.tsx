import { Download, Puzzle } from 'lucide-react';
import { type ReactElement, useState } from 'react';

import { downloadSiteAgentPlugin, useSiteAgentVersion } from '../api';
import { Button, Card } from '../components/ui';

/** Always-at-hand download of the companion WordPress agent plugin + its bundled version. */
function SiteAgentPluginCard(): ReactElement {
    const { data: version } = useSiteAgentVersion();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(false);

    const download = (): void => {
        setBusy(true);
        setError(false);
        downloadSiteAgentPlugin()
            .catch(() => setError(true))
            .finally(() => setBusy(false));
    };

    return (
        <Card title="Agente Imagina (plugin del sitio)">
            <div className="ir-flex ir-flex-col ir-gap-3">
                <p className="ir-flex ir-items-center ir-gap-2 ir-text-sm ir-text-muted-foreground">
                    <Puzzle className="ir-size-4 ir-text-primary" />
                    El plugin que se instala en cada WordPress para leer respaldos, salud del sitio e historial de actualizaciones.
                    {version != null && (
                        <span className="ir-rounded-full ir-bg-muted ir-px-2 ir-py-0.5 ir-font-mono ir-text-xs ir-text-foreground">v{version}</span>
                    )}
                </p>
                <div className="ir-flex ir-items-center ir-gap-3">
                    <Button onClick={download} disabled={busy}>
                        <Download className="ir-size-4" />
                        {busy ? 'Preparando…' : 'Descargar plugin del agente'}
                    </Button>
                    {error && <span className="ir-text-xs ir-text-red-500">No se pudo descargar. Inténtalo de nuevo.</span>}
                </div>
                <p className="ir-text-xs ir-text-muted-foreground">
                    Instálalo en el sitio: Plugins → Añadir nuevo → Subir plugin → elige el ZIP (no hay que descomprimirlo). Si ya está instalado,
                    súbelo igual y elige «Reemplazar» para actualizarlo a esta versión.
                </p>
            </div>
        </Card>
    );
}

export function SystemScreen(): ReactElement {
    return (
        <div className="ir-flex ir-flex-col ir-gap-6">
            <SiteAgentPluginCard />
        </div>
    );
}
