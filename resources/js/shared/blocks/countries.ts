/**
 * Country codes → names in the report's language. Sources name countries differently: Google
 * Analytics sends names («Colombia»), Search Console ISO 3166-1 alpha-3 codes («col»), Cloudflare
 * and CrowdSec alpha-2 codes («CO»). Codes are turned into names (Intl.DisplayNames) so a client
 * never reads «col» and the world map can place every source. Only applied where the column is
 * known to hold countries — a two-letter value elsewhere may be a language («es»).
 */

// ISO 3166-1 alpha-3 → alpha-2 (generated from the ISO list; 249 entries).
const ALPHA3 = 'abw:AW afg:AF ago:AO aia:AI ala:AX alb:AL and:AD are:AE arg:AR arm:AM asm:AS ata:AQ atf:TF atg:AG aus:AU aut:AT aze:AZ bdi:BI bel:BE ben:BJ bes:BQ bfa:BF bgd:BD bgr:BG bhr:BH bhs:BS bih:BA blm:BL blr:BY blz:BZ bmu:BM bol:BO bra:BR brb:BB brn:BN btn:BT bvt:BV bwa:BW caf:CF can:CA cck:CC che:CH chl:CL chn:CN civ:CI cmr:CM cod:CD cog:CG cok:CK col:CO com:KM cpv:CV cri:CR cub:CU cuw:CW cxr:CX cym:KY cyp:CY cze:CZ deu:DE dji:DJ dma:DM dnk:DK dom:DO dza:DZ ecu:EC egy:EG eri:ER esh:EH esp:ES est:EE eth:ET fin:FI fji:FJ flk:FK fra:FR fro:FO fsm:FM gab:GA gbr:GB geo:GE ggy:GG gha:GH gib:GI gin:GN glp:GP gmb:GM gnb:GW gnq:GQ grc:GR grd:GD grl:GL gtm:GT guf:GF gum:GU guy:GY hkg:HK hmd:HM hnd:HN hrv:HR hti:HT hun:HU idn:ID imn:IM ind:IN iot:IO irl:IE irn:IR irq:IQ isl:IS isr:IL ita:IT jam:JM jey:JE jor:JO jpn:JP kaz:KZ ken:KE kgz:KG khm:KH kir:KI kna:KN kor:KR kwt:KW lao:LA lbn:LB lbr:LR lby:LY lca:LC lie:LI lka:LK lso:LS ltu:LT lux:LU lva:LV mac:MO maf:MF mar:MA mco:MC mda:MD mdg:MG mdv:MV mex:MX mhl:MH mkd:MK mli:ML mlt:MT mmr:MM mne:ME mng:MN mnp:MP moz:MZ mrt:MR msr:MS mtq:MQ mus:MU mwi:MW mys:MY myt:YT nam:NA ncl:NC ner:NE nfk:NF nga:NG nic:NI niu:NU nld:NL nor:NO npl:NP nru:NR nzl:NZ omn:OM pak:PK pan:PA pcn:PN per:PE phl:PH plw:PW png:PG pol:PL pri:PR prk:KP prt:PT pry:PY pse:PS pyf:PF qat:QA reu:RE rou:RO rus:RU rwa:RW sau:SA sdn:SD sen:SN sgp:SG sgs:GS shn:SH sjm:SJ slb:SB sle:SL slv:SV smr:SM som:SO spm:PM srb:RS ssd:SS stp:ST sur:SR svk:SK svn:SI swe:SE swz:SZ sxm:SX syc:SC syr:SY tca:TC tcd:TD tgo:TG tha:TH tjk:TJ tkl:TK tkm:TM tls:TL ton:TO tto:TT tun:TN tur:TR tuv:TV twn:TW tza:TZ uga:UG ukr:UA umi:UM ury:UY usa:US uzb:UZ vat:VA vct:VC ven:VE vgb:VG vir:VI vnm:VN vut:VU wlf:WF wsm:WS yem:YE zaf:ZA zmb:ZM zwe:ZW';

const ALPHA3_TO_2: Record<string, string> = Object.fromEntries(ALPHA3.split(' ').map((pair) => pair.split(':') as [string, string]));

/** Codes the sources use for «we don't know» or for anonymising networks. */
const SPECIAL: Record<string, string> = { zzz: 'Desconocido', xx: 'Desconocido', zz: 'Desconocido', t1: 'Red Tor' };

function displayNames(locale: string): Intl.DisplayNames | null {
    try {
        return new Intl.DisplayNames([locale], { type: 'region' });
    } catch {
        return null;
    }
}

/** The ISO alpha-2 code for a two- or three-letter country code, or null when it isn't one. */
export function countryCode(value: string): string | null {
    const raw = value.trim();
    if (/^[A-Za-z]{3}$/.test(raw)) {
        return ALPHA3_TO_2[raw.toLowerCase()] ?? null;
    }
    if (/^[A-Za-z]{2}$/.test(raw)) {
        const code = raw.toUpperCase();
        const name = displayNames('en')?.of(code);

        return name !== undefined && name !== code ? code : null;
    }

    return null;
}

let englishIndex: Map<string, string> | null = null;

/** English country name (as Google Analytics sends it) → alpha-2 code. */
function codeFromEnglish(name: string): string | null {
    if (englishIndex === null) {
        englishIndex = new Map();
        const english = displayNames('en');
        if (english !== null) {
            for (const code of Object.values(ALPHA3_TO_2)) {
                const label = english.of(code);
                if (label !== undefined) englishIndex.set(label.toLowerCase(), code);
            }
        }
    }

    return englishIndex.get(name.trim().toLowerCase()) ?? null;
}

/** «col» / «CO» / «United States» → «Colombia» / «Estados Unidos» (in the given language). */
export function countryLabel(value: string, locale = 'es'): string {
    const special = SPECIAL[value.trim().toLowerCase()];
    if (special !== undefined) {
        return special;
    }
    const code = countryCode(value) ?? codeFromEnglish(value);
    if (code === null) {
        return value;
    }

    return displayNames(locale)?.of(code) ?? value;
}

/** The English name the world atlas understands, for codes and names alike. */
export function countryForMap(value: string): string {
    const code = countryCode(value);

    return code !== null ? (displayNames('en')?.of(code) ?? value) : value;
}
