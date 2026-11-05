import { X } from 'lucide-react';
import { type ReactElement, useState } from 'react';

import { BlockList } from '@shared/blocks/BlockRenderer';
import type { Block } from '@shared/blocks/types';

import type { ReportTheme } from '../types';
import { Segmented } from '../components/ui';

type Device = 'desktop' | 'phone';

/**
 * «Ver como el cliente»: the report exactly as the portal renders it — same BlockList, page
 * navigation, fitted rows and hidden empty blocks — on a desktop width or inside a phone
 * frame (the phone frame is a size container, so the grid stacks like on a real phone).
 */
export function ClientPreview({
    blocks,
    data,
    theme,
    pages,
    agency,
    context,
    currency,
    onClose,
}: {
    blocks: Block[];
    data: Record<string, unknown>;
    theme: ReportTheme;
    pages: { name?: string }[];
    agency: { name?: string | null; logo_url?: string | null } | null;
    context: Record<string, string>;
    currency?: string;
    onClose: () => void;
}): ReactElement {
    const [device, setDevice] = useState<Device>('desktop');
    const report = <BlockList blocks={blocks} data={data} context={context} currency={currency} locale="es" theme={theme} pages={pages} agency={agency} mode="paged" />;

    return (
        <div className="ir-fixed ir-inset-0 ir-z-50 ir-flex ir-flex-col ir-bg-muted">
            <div className="ir-flex ir-items-center ir-gap-3 ir-border-b ir-bg-card ir-px-4 ir-py-2.5">
                <div className="ir-min-w-0 ir-flex-1">
                    <p className="ir-truncate ir-text-sm ir-font-semibold">Así lo verá tu cliente</p>
                    <p className="ir-hidden ir-truncate ir-text-xs ir-text-muted-foreground sm:ir-block">Con los datos de la vista previa. Los bloques sin datos se ocultan, como en el portal y el PDF.</p>
                </div>
                <Segmented<Device>
                    value={device}
                    onChange={setDevice}
                    items={[
                        { value: 'desktop', label: 'Escritorio' },
                        { value: 'phone', label: 'Celular' },
                    ]}
                />
                <button type="button" onClick={onClose} aria-label="Cerrar vista previa" className="ir-rounded-md ir-p-1.5 ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground">
                    <X className="ir-size-5" />
                </button>
            </div>

            <div className="ir-flex-1 ir-overflow-y-auto">
                {device === 'desktop' ? (
                    <div className="ir-mx-auto ir-max-w-6xl ir-px-4 ir-py-6 sm:ir-px-6">{report}</div>
                ) : (
                    <div className="ir-flex ir-justify-center ir-px-4 ir-py-6">
                        <div className="ir-relative ir-w-[390px] ir-max-w-full ir-overflow-hidden ir-rounded-[2.25rem] ir-border-[10px] ir-border-foreground/90 ir-bg-muted/40 ir-shadow-ir-lg">
                            <div className="ir-flex ir-h-6 ir-items-center ir-justify-center ir-bg-foreground/90">
                                <span className="ir-h-1.5 ir-w-16 ir-rounded-full ir-bg-background/30" />
                            </div>
                            <div className="ir-phone-frame ir-h-[72vh] ir-overflow-y-auto ir-p-3">{report}</div>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
