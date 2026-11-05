import { RefreshCw } from 'lucide-react';
import type { ReactElement } from 'react';

import type { AiChoice, AiModelOption } from '../types';
import { Field, Select } from './ui';

/**
 * Model + effort pickers for the AI settings (agency and superadmin). The choices come live from
 * Anthropic's model list: «el más reciente» of each family updates itself when a new model comes
 * out; a fixed model stays until Anthropic retires it. The effort levels are the ones the model in
 * use accepts. `inherit*` adds the «use the platform's» option (agency only).
 */
export function AiModelFields({
    choices,
    model,
    onModel,
    modelNow,
    inheritModel,
    efforts,
    effort,
    onEffort,
    effortNow,
    inheritEffort,
    modelKnown,
    disabled,
    disabledHint,
    error,
    onRefresh,
    refreshing,
}: {
    choices: AiChoice[];
    /** '' = the inherited option (agency without its own choice). */
    model: string;
    onModel: (value: string) => void;
    modelNow: string;
    inheritModel?: string;
    efforts: AiModelOption[];
    effort: string;
    onEffort: (value: string) => void;
    effortNow: string | null;
    inheritEffort?: string;
    /** False while Anthropic's list couldn't be read. */
    modelKnown: boolean;
    disabled: boolean;
    disabledHint?: string;
    error: string | null;
    onRefresh?: () => void;
    refreshing?: boolean;
}): ReactElement {
    const auto = choices.filter((choice) => choice.group === 'auto');
    const fixed = choices.filter((choice) => choice.group === 'fixed');
    const selected = choices.find((choice) => choice.id === model);
    // A saved choice the list doesn't show (e.g. the list couldn't be read) still shows as selected.
    const orphan = model !== '' && selected === undefined;
    const selectedEffort = efforts.find((option) => option.id === effort);

    const modelHint = disabled
        ? disabledHint
        : model === '' && inheritModel !== undefined
          ? `Usas el de la plataforma: ${modelNow}.`
          : [selected?.hint, selected?.group === 'auto' || orphan ? `Ahora se usa ${modelNow}.` : null].filter(Boolean).join(' ') || undefined;

    const effortHint = disabled
        ? undefined
        : !modelKnown
          ? 'Se podrá elegir en cuanto se pueda leer la lista de modelos de Anthropic.'
          : efforts.length === 0
          ? `${modelNow} no permite elegir el nivel de esfuerzo.`
          : effort === '' && inheritEffort !== undefined
            ? `Usas el de la plataforma: ${(effortNow ?? inheritEffort).toLowerCase()}.`
            : selectedEffort !== undefined
              ? selectedEffort.hint
              : effortNow !== null
                ? `Este modelo no acepta ese nivel: se usa ${effortNow.toLowerCase()}.`
                : undefined;

    return (
        <div className="ir-flex ir-flex-col ir-gap-3">
            <div className="ir-grid ir-gap-4 sm:ir-grid-cols-2">
                <Field label="Modelo" hint={modelHint}>
                    <Select value={model} disabled={disabled} onChange={(event) => onModel(event.target.value)}>
                        {inheritModel !== undefined && <option value="">El de la plataforma ({inheritModel})</option>}
                        {orphan && <option value={model}>{modelNow}</option>}
                        {auto.length > 0 && (
                            <optgroup label="Se actualiza solo">
                                {auto.map((choice) => (
                                    <option key={choice.id} value={choice.id}>
                                        {choice.label}
                                    </option>
                                ))}
                            </optgroup>
                        )}
                        {fixed.length > 0 && (
                            <optgroup label="Un modelo fijo">
                                {fixed.map((choice) => (
                                    <option key={choice.id} value={choice.id}>
                                        {choice.label}
                                    </option>
                                ))}
                            </optgroup>
                        )}
                    </Select>
                </Field>
                <Field label="Nivel de esfuerzo" hint={effortHint}>
                    <Select value={effort} disabled={disabled || efforts.length === 0} onChange={(event) => onEffort(event.target.value)}>
                        {inheritEffort !== undefined && <option value="">El de la plataforma ({inheritEffort.toLowerCase()})</option>}
                        {effort !== '' && selectedEffort === undefined && <option value={effort}>{effortNow ?? effort}</option>}
                        {efforts.map((option) => (
                            <option key={option.id} value={option.id}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                </Field>
            </div>
            {error !== null && (
                <p className="ir-rounded-lg ir-bg-warning/10 ir-px-3 ir-py-2 ir-text-xs ir-text-warning">
                    No se pudo leer la lista de modelos de Anthropic: {error}
                    {choices.length > 0 ? ' Mientras tanto se usa la última lista conocida.' : ''}
                </p>
            )}
            {onRefresh !== undefined && !disabled && (
                <p className="ir-flex ir-flex-wrap ir-items-center ir-gap-2 ir-text-xs ir-text-muted-foreground">
                    <span>La lista viene de Anthropic y se renueva sola cada 12 horas.</span>
                    <button
                        type="button"
                        className="ir-inline-flex ir-items-center ir-gap-1 ir-font-medium ir-text-primary hover:ir-underline disabled:ir-opacity-50"
                        onClick={onRefresh}
                        disabled={refreshing}
                    >
                        <RefreshCw className={`ir-size-3 ${refreshing === true ? 'ir-animate-spin' : ''}`} />
                        {refreshing === true ? 'Buscando…' : 'Buscar modelos nuevos'}
                    </button>
                </p>
            )}
        </div>
    );
}
