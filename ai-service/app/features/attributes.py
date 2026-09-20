"""Syntactic secondary evidence, never a category/SKU classifier.

The RULES registry is the single source of truth: every pattern lives here as
an AttributeRule with a stable rule id, so new description conventions are
added by registering a rule instead of hardcoding SKUs. parse_attributes()
and compatibility() keep their exact legacy shapes because the retrieval
pipeline and stored sidecars depend on them; parse_detailed() exposes the
same matches with raw text, source and rule id for audit and future use.
Raw descriptions are never modified.
"""
import re
from collections.abc import Callable
from dataclasses import dataclass

VERSION = 'description-attributes-v2'
SOURCE = 'description_parser'

# Description cap: bounds regex work on pathological inputs.
MAX_TEXT = 4000


def _default_normalize(value: str) -> str:
    return re.sub(r'\s+', '', value).replace(',', '.')


def _drive_normalize(value: str) -> str:
    """Canonical tip vocabulary (ID + EN) so variants match across catalogs.

    PLUS/PHILLIPS/PHILIPS/CROSS/KEMBANG -> PHILLIPS (plus/kembang),
    MINUS/FLAT/FLATHEAD/SLOTTED -> FLAT (minus). PH/PZ codes and TORX keep
    their specific value so PH2 vs PH1 stay distinct; family overlap is
    handled in compatibility(), not by collapsing codes.
    """
    token = re.sub(r'\s+', '', value).replace(',', '.').upper()
    if token in ('PLUS', 'PHILLIPS', 'PHILIPS', 'CROSS', 'KEMBANG'):
        return 'PHILLIPS'
    if token in ('MINUS', 'FLAT', 'FLATHEAD', 'SLOTTED'):
        return 'FLAT'
    return token


def _measurement_normalize(value: str) -> str:
    token = re.sub(r'\s+', '', value).replace(',', '.').upper()
    # Inch shorthand: 6' / 6’ means 6 inch, canonical is 6".
    if token.endswith("'") or token.endswith('\u2019'):
        return token[:-1] + '"'
    return token


def _dimension_normalize(value: str) -> str:
    token = re.sub(r'\s+', '', value).replace(',', '.').upper()
    token = token.replace('\u00d7', 'X').replace('*', 'X')
    if token.endswith("'") or token.endswith('\u2019'):
        token = token[:-1] + '"'
    return token


def _length_normalize(value: str) -> str:
    token = re.sub(r'\s+', '', value).upper()
    if token in ('PANJANG', 'LONG'):
        return 'LONG'
    if token in ('PENDEK', 'SHORT'):
        return 'SHORT'
    return token


@dataclass(frozen=True)
class AttributeRule:
    """One extensible description pattern.

    key groups matches for compatibility scoring; id names the rule for
    audit (never a probability). normalizer maps the raw match to the
    canonical value; raw text is always preserved alongside it.
    """
    id: str
    key: str
    pattern: str
    normalizer: Callable[[str], str] = _default_normalize


RULES: tuple[AttributeRule, ...] = (
    # Obeng/screwdriver tip in ID + EN: PLUS/MINUS/KEMBANG plus PH/PZ codes.
    # Normalizer maps synonyms to PHILLIPS/FLAT so "Obeng Plus" matches
    # "PHILLIPS" and "Obeng Minus" matches "FLAT" even without a PH code.
    AttributeRule('drive', 'drive',
                  r'\b(PH[0-3]|PZ[0-3]|PHILLIPS|PHILIPS|CROSS|KEMBANG|PLUS|FLATHEAD|FLAT|SLOTTED|MINUS|TORX)\b',
                  _drive_normalize),
    # Paired dimensions like 6x150mm / 6X150 / 19*11*6.5cm keep the full form
    # so diameter x length variants stay distinct (6x150 vs 6x100).
    AttributeRule('dimension', 'dimension',
                  r'\b\d+(?:[.,]\d+)?\s*[xX\u00d7*]\s*\d+(?:[.,]\d+)?(?:\s*[xX\u00d7*]\s*\d+(?:[.,]\d+)?)?\s*(?:MM|CM|M|INCH|IN|["\u2033\'\u2019])?',
                  _dimension_normalize),
    AttributeRule('measurement', 'measurement',
                  r'\b\d+(?:[.,]\d+)?\s*(?:MM|CM|M|INCH|IN)(?!\w)|\b\d+(?:[.,]\d+)?\s*["\u2033\'\u2019]',
                  _measurement_normalize),
    # Panjang/pendek words: foto tanpa skala tidak selalu membedakan panjang
    # fisik, so an explicit word cue is kept as secondary evidence.
    AttributeRule('length', 'length', r'\b(PANJANG|PENDEK|LONG|SHORT)\b',
                  _length_normalize),
    # Bare numbers are ambiguous (38 could be a size, a count, or noise), so
    # sizes require an explicit cue; variant/model guesses stay out.
    AttributeRule('size', 'size', r'\b(?:SIZE|UKURAN|SZ)\s*[:=-]?\s*\d+(?:[.,]\d+)?\b'),
    # Model keeps dash suffixes (JC403-4 vs JC403-6) so length variants with
    # the same base model stay distinct instead of collapsing to JC403.
    AttributeRule('model', 'model', r'\b[A-Z]{1,6}[-_]?\d{2,8}(?:[-_]\d{1,2}[A-Z]?)?[A-Z]?\b'),
    AttributeRule('quantity', 'quantity', r'\b\d+\s*(?:PCS|PC|PACK|SET|PAIR)\b'),
    AttributeRule('color', 'color',
                  r'\b(?:RED|BLUE|GREEN|BLACK|WHITE|YELLOW|MERAH|BIRU|HIJAU|HITAM|PUTIH|KUNING)\b'),
)

# Backwards-compatible view of the registry for the legacy scoring path.
# NOTE: several rules share normalizers, so callers must use parse_detailed
# (or parse_attributes below, which applies normalizers) instead of raw regex.
PATTERNS = {rule.key: rule.pattern for rule in RULES}

# Drive families for partial matching: PH codes + PLUS words are the plus
# (phillips/kembang/cross) family, FLAT words are the minus family. TORX and
# PZ stay distinct families so they never match plus/minus.
_PHILIPS_FAMILY = {'PH0', 'PH1', 'PH2', 'PH3', 'PHILLIPS'}
_FLAT_FAMILY = {'FLAT'}
_PZ_FAMILY = {'PZ0', 'PZ1', 'PZ2', 'PZ3'}


def register_rule(rule: AttributeRule) -> tuple[AttributeRule, ...]:
    """Return an extended registry with one appended rule.

    The global RULES tuple is intentionally immutable: callers that need a
    custom set thread the returned tuple through parse_detailed(..., rules=...).
    Appending never reorders existing rules, so legacy output is unaffected.
    """
    if not rule.id or not rule.key or not rule.pattern:
        raise ValueError('Rule needs an id, key and pattern')
    if any(existing.id == rule.id for existing in RULES):
        raise ValueError(f'Duplicate rule id: {rule.id}')
    re.compile(rule.pattern)
    return (*RULES, rule)


def parse_detailed(text: str, rules: tuple[AttributeRule, ...] = RULES) -> list[dict]:
    """Structured matches: key, normalized value, raw match, source, rule id.

    Matching runs on the raw text (case-insensitive) so raw spans stay exact
    even where upper() would shift string length; values are normalized from
    the uppercased match exactly like the legacy path.
    """
    raw_text = str(text or '')[:MAX_TEXT]
    records = []
    for rule in rules:
        compiled = re.compile(rule.pattern, re.IGNORECASE)
        for match in compiled.finditer(raw_text):
            records.append({'key': rule.key, 'value': rule.normalizer(match.group(0).upper()),
                            'raw': match.group(0), 'source': SOURCE, 'rule': rule.id})
    return records


def parse_attributes(text: str) -> dict:
    raw_text = str(text or '')[:MAX_TEXT]
    out: dict[str, set[str]] = {}
    for rule in RULES:
        compiled = re.compile(rule.pattern, re.IGNORECASE)
        for match in compiled.finditer(raw_text):
            out.setdefault(rule.key, set()).add(rule.normalizer(match.group(0).upper()))
    return {key: sorted(vals) for key, vals in out.items() if vals}


def _drive_score(query_vals: set[str], ref_vals: set[str]) -> float:
    """Exact Jaccard, with 0.5 partial credit for same tip family.

    PH2 vs PHILLIPS (or PLUS vs PH2) share the philips family but differ in
    specificity, so they score half instead of zero. PH2 vs PH1 or plus vs
    minus stay zero. TORX/PZ never match other families.
    """
    if query_vals & ref_vals:
        return len(query_vals & ref_vals) / len(query_vals | ref_vals)
    q_families = {_drive_family(v) for v in query_vals}
    r_families = {_drive_family(v) for v in ref_vals}
    q_families.discard(None)
    r_families.discard(None)
    if q_families & r_families:
        return 0.5
    return 0.0


def _drive_family(value: str) -> str | None:
    if value in _PHILIPS_FAMILY:
        return 'philips'
    if value in _FLAT_FAMILY:
        return 'flat'
    if value in _PZ_FAMILY:
        return 'pozidrive'
    if value == 'TORX':
        return 'torx'
    return None


def compatibility(query: dict, reference: dict) -> float | None:
    common = set(query) & set(reference)
    if not common:
        return None
    scores = []
    for key in common:
        q, r = set(query[key]), set(reference[key])
        if key == 'drive':
            scores.append(_drive_score(q, r))
        else:
            scores.append(len(q & r) / len(q | r) if (q | r) else 0.0)
    return sum(scores) / len(scores)
