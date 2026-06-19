import { type ReactElement } from 'react';

import type { Block } from '@shared/blocks/types';

import { useCreateReportTemplate, useDeleteReportTemplate, useReportTemplates } from '../api';
import { Button, Card } from '../components/ui';
import { useAdminUi } from '../store';
import type { ReportTemplateDto } from '../types';

export function TemplatesScreen(): ReactElement {
    const { data: templates = [] } = useReportTemplates();
    const editTemplate = useAdminUi((state) => state.editTemplate);
    const create = useCreateReportTemplate();
    const remove = useDeleteReportTemplate();

    const duplicate = (template: ReportTemplateDto): void => {
        create.mutate({ name: `${template.name} (copia)`, blocks: template.blocks as Block[] });
    };

    const confirmRemove = (id: number): void => {
        if (window.confirm('¿Eliminar esta plantilla? No afecta a los reportes ya generados.')) {
            remove.mutate(id);
        }
    };

    return (
        <div className="ir-flex ir-flex-col ir-gap-6">
            <Card title="Plantillas de reportes">
                <div className="ir-mb-4">
                    <Button onClick={() => editTemplate(null)}>+ Nueva plantilla</Button>
                </div>

                <ul className="ir-flex ir-flex-col ir-gap-3">
                    {templates.map((template) => (
                        <li
                            key={template.id}
                            className="ir-flex ir-items-center ir-justify-between ir-border-t ir-pt-3"
                        >
                            <div>
                                <p className="ir-flex ir-items-center ir-gap-2 ir-font-medium">
                                    {template.name}
                                    {template.is_default && (
                                        <span className="ir-rounded ir-bg-muted ir-px-2 ir-py-0.5 ir-text-xs ir-font-normal ir-text-muted-foreground">
                                            por defecto
                                        </span>
                                    )}
                                </p>
                                <p className="ir-text-xs ir-text-muted-foreground">
                                    {template.blocks.length} bloques · {template.locale}
                                </p>
                            </div>
                            <div className="ir-flex ir-gap-2">
                                <Button variant="ghost" onClick={() => editTemplate(template.id)}>
                                    Editar
                                </Button>
                                <Button variant="ghost" onClick={() => duplicate(template)} disabled={create.isPending}>
                                    Duplicar
                                </Button>
                                <Button variant="ghost" onClick={() => confirmRemove(template.id)} disabled={remove.isPending}>
                                    Eliminar
                                </Button>
                            </div>
                        </li>
                    ))}
                    {templates.length === 0 && (
                        <li className="ir-text-sm ir-text-muted-foreground">
                            Aún no hay plantillas. Crea una con «Nueva plantilla» (se abre el editor).
                        </li>
                    )}
                </ul>
            </Card>
        </div>
    );
}
