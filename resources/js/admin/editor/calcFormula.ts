/**
 * Client-side validation for calculated-metric formulas — mirrors the server's safe
 * FormulaEvaluator (App\Reports\Calc\FormulaEvaluator): `+ - * / ( )`, numbers and metric
 * identifiers (e.g. `ga4.sessions`). Gives instant valid/invalid feedback in the fx editor;
 * the server remains the source of truth (it recomputes on preview/generate).
 */
export interface FormulaCheck {
    ok: boolean;
    error?: string;
}

const NUMBER = /[0-9.]/;
const IDENT_START = /[a-zA-Z_]/;
const IDENT_BODY = /[a-zA-Z0-9_.]/;

export function validateFormula(formula: string, knownKeys: Set<string>): FormulaCheck {
    const text = formula.trim();
    if (text === '') {
        return { ok: false, error: 'Fórmula vacía' };
    }

    const tokens: string[] = [];
    let i = 0;
    while (i < text.length) {
        const char = text[i] ?? '';
        if (/\s/.test(char)) {
            i += 1;
            continue;
        }
        if ('+-*/()'.includes(char)) {
            tokens.push(char);
            i += 1;
            continue;
        }
        if (NUMBER.test(char)) {
            let num = '';
            while (i < text.length && NUMBER.test(text[i] ?? '')) {
                num += text[i];
                i += 1;
            }
            tokens.push(num);
            continue;
        }
        if (IDENT_START.test(char)) {
            let id = '';
            while (i < text.length && IDENT_BODY.test(text[i] ?? '')) {
                id += text[i];
                i += 1;
            }
            tokens.push(id);
            continue;
        }
        return { ok: false, error: `Carácter no válido: «${char}»` };
    }

    let depth = 0;
    for (const token of tokens) {
        if (token === '(') {
            depth += 1;
        } else if (token === ')') {
            depth -= 1;
            if (depth < 0) {
                return { ok: false, error: 'Paréntesis desbalanceados' };
            }
        }
    }
    if (depth !== 0) {
        return { ok: false, error: 'Paréntesis desbalanceados' };
    }

    for (const token of tokens) {
        if (IDENT_START.test(token[0] ?? '') && !knownKeys.has(token)) {
            return { ok: false, error: `Métrica desconocida: «${token}»` };
        }
    }

    const hasOperand = tokens.some((token) => NUMBER.test(token[0] ?? '') || IDENT_START.test(token[0] ?? ''));
    if (!hasOperand) {
        return { ok: false, error: 'Falta un valor' };
    }

    return { ok: true };
}
