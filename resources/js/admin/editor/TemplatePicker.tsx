import { FilePlus2, LayoutTemplate, Link2, Sparkles, X } from 'lucide-react';
import { type ReactElement, type ReactNode, useMemo } from 'react';

import type { Block } from '@shared/blocks/types';

import { TemplateThumbnail } from '../components/TemplateThumbnail';
import { Modal } from '../components/ui';
import type { ReportTemplateDto } from '../types';
import { GALLERY, type GalleryTemplate } from './templateGallery';

function Tile({ thumbnail, title, text, meta, onClick, disabled }: { thumbnail: ReactNode; title: string; text?: string; meta?: string; onClick: () => void; disabled?: boolean }): ReactElement {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            className="ir-group ir-flex ir-flex-col ir-overflow-hidden ir-rounded-xl ir-border ir-bg-card ir-text-left ir-transition hover:ir-border-accent/60 hover:ir-shadow-ir-sm disabled:ir-opacity-50"
        >
            <span className="ir-relative ir-block ir-h-28 ir-overflow-hidden ir-border-b ir-bg-muted/40 ir-px-6 ir-pt-4">
                <span className="ir-block ir-h-full ir-rounded-t-md ir-bg-card ir-p-1.5 ir-shadow-ir-sm ir-transition-transform group-hover:-ir-translate-y-0.5">{thumbnail}</span>
            </span>
            <span className="ir-flex ir-flex-1 ir-flex-col ir-gap-0.5 ir-p-3">
                <span className="ir-text-sm ir-font-semibold ir-leading-snug">{title}</span>
                {text !== undefined && <span className="ir-line-clamp-2 ir-text-xs ir-leading-relaxed ir-text-muted-foreground">{text}</span>}
                {meta !== undefined && <span className="ir-mt-auto ir-pt-1 ir-text-[11px] ir-font-medium ir-text-accent">{meta}</span>}
            </span>
        </button>
    );
}

function Action({ icon, title, text, onClick }: { icon: ReactNode; title: string; text: string; onClick: () => void }): ReactElement {
    return (
        <button type="button" onClick={onClick} className="ir-flex ir-items-start ir-gap-3 ir-rounded-xl ir-border ir-bg-card ir-p-4 ir-text-left ir-transition hover:ir-border-accent/60 hover:ir-shadow-ir-sm">
            <span className="ir-flex ir-size-9 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-lg ir-bg-accent/10 ir-text-accent">{icon}</span>
            <span>
                <span className="ir-block ir-text-sm ir-font-semibold">{title}</span>
                <span className="ir-mt-0.5 ir-block ir-text-xs ir-leading-relaxed ir-text-muted-foreground">{text}</span>
            </span>
        </button>
    );
}

/**
 * Every way to start (or extend) a report in one place: a ready model, one of the agency's
 * own templates, AI, or a blank page — each model shown as a thumbnail, not a list of names.
 * It opens by itself on a new template, and from «Plantillas» while editing.
 */
export function TemplatePicker({
    mode,
    templates,
    onGallery,
    onDefault,
    onOwn,
    onAi,
    onBlank,
    onClose,
    defaultBusy = false,
}: {
    mode: 'start' | 'insert';
    templates: ReportTemplateDto[];
    onGallery: (template: GalleryTemplate) => void;
    onDefault: () => void;
    onOwn: (template: ReportTemplateDto) => void;
    onAi: () => void;
    onBlank: () => void;
    onClose: () => void;
    defaultBusy?: boolean;
}): ReactElement {
    // Built once: each build() mints fresh block ids.
    const previews = useMemo(() => GALLERY.map((template) => ({ template, blocks: template.build() })), []);
    const usedBy = (template: ReportTemplateDto): string | undefined => {
        const count = template.used_by?.length ?? 0;

        return count === 0 ? undefined : `Usada por ${count} ${count === 1 ? 'cliente' : 'clientes'}`;
    };

    return (
        <Modal onClose={onClose} className="ir-max-w-6xl">
            <div className="ir-flex ir-max-h-[86vh] ir-flex-col ir-overflow-hidden ir-rounded-2xl ir-border ir-bg-background ir-shadow-ir-lg">
                <div className="ir-flex ir-items-start ir-justify-between ir-gap-4 ir-border-b ir-bg-card ir-px-6 ir-py-5">
                    <div>
                        <h2 className="ir-text-lg ir-font-semibold ir-tracking-tight">{mode === 'start' ? '¿Cómo quieres empezar?' : 'Plantillas'}</h2>
                        <p className="ir-mt-0.5 ir-text-[13px] ir-text-muted-foreground">
                            {mode === 'start'
                                ? 'Parte de un modelo listo, de una plantilla tuya o deja que la IA lo arme con los datos del sitio. Todo se puede editar después.'
                                : 'Si ya tienes contenido, te preguntamos si añadirla debajo o reemplazar lo que hay.'}
                        </p>
                    </div>
                    <button type="button" onClick={onClose} aria-label="Cerrar" className="ir-rounded-md ir-p-1.5 ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground">
                        <X className="ir-size-4" />
                    </button>
                </div>

                <div className="ir-flex ir-flex-col ir-gap-7 ir-overflow-y-auto ir-px-6 ir-py-6">
                    <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2">
                        <Action icon={<Sparkles className="ir-size-4" />} title="Créala con IA" text="Elige un sitio y la IA arma el informe con las fuentes que tiene conectadas." onClick={onAi} />
                        {mode === 'start' ? (
                            <Action icon={<FilePlus2 className="ir-size-4" />} title="Empezar en blanco" text="Solo la cabecera; arrastra los bloques que quieras desde el panel." onClick={onBlank} />
                        ) : (
                            <Action icon={<LayoutTemplate className="ir-size-4" />} title="Plantilla por defecto" text="El informe narrativo estándar: salud, resumen, KPIs, seguridad, tráfico y trabajo." onClick={onDefault} />
                        )}
                    </div>

                    {templates.length > 0 && (
                        <section>
                            <h3 className="ir-mb-1 ir-flex ir-items-center ir-gap-2 ir-text-sm ir-font-semibold">
                                <Link2 className="ir-size-4 ir-text-muted-foreground" />
                                Tus plantillas
                            </h3>
                            <p className="ir-mb-3 ir-text-xs ir-text-muted-foreground">
                                {mode === 'start' ? 'Se abre una copia: la original y los clientes que la usan no cambian.' : 'Añade su contenido al informe que estás editando.'}
                            </p>
                            <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2 lg:ir-grid-cols-3 xl:ir-grid-cols-4">
                                {templates.map((template) => (
                                    <Tile
                                        key={template.id}
                                        thumbnail={<TemplateThumbnail blocks={template.blocks as Block[]} />}
                                        title={template.name}
                                        meta={usedBy(template)}
                                        onClick={() => onOwn(template)}
                                    />
                                ))}
                            </div>
                        </section>
                    )}

                    <section>
                        <h3 className="ir-mb-1 ir-text-sm ir-font-semibold">Modelos listos</h3>
                        <p className="ir-mb-3 ir-text-xs ir-text-muted-foreground">Diseños por tipo de cliente. Los bloques sin datos se ocultan solos en cada reporte.</p>
                        <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2 lg:ir-grid-cols-3 xl:ir-grid-cols-4">
                            {mode === 'start' && (
                                <Tile
                                    thumbnail={
                                        <span className="ir-flex ir-h-full ir-items-center ir-justify-center ir-text-accent">
                                            <LayoutTemplate className="ir-size-8" />
                                        </span>
                                    }
                                    title="Plantilla por defecto"
                                    text="El informe narrativo estándar: salud, resumen, KPIs, seguridad, tráfico y trabajo."
                                    onClick={onDefault}
                                    disabled={defaultBusy}
                                />
                            )}
                            {previews.map(({ template, blocks }) => (
                                <Tile key={template.key} thumbnail={<TemplateThumbnail blocks={blocks} />} title={template.name} text={template.description} onClick={() => onGallery(template)} />
                            ))}
                        </div>
                    </section>
                </div>
            </div>
        </Modal>
    );
}
