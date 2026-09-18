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

VERSION = 'description-attributes-v1'
SOURCE = 'description_parser'

# Description cap: bounds regex work on pathological inputs.
MAX_TEXT = 4000


def _default_normalize(value: str) -> str:
    return re.sub(r'\s+', '', value).replace(',', '.')


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
    AttributeRule('drive', 'drive', r'\b(PH[123]|PHILLIPS|FLAT|SLOTTED)\b'),
    AttributeRule('measurement', 'measurement',
                  r'\b\d+(?:[.,]\d+)?\s*(?:MM|CM|M|INCH|IN)(?!\w)|\b\d+(?:[.,]\d+)?\s*["″]'),
    # Bare numbers are ambiguous (38 could be a size, a count, or noise), so
    # sizes require an explicit cue; variant/model guesses stay out.
    AttributeRule('size', 'size', r'\b(?:SIZE|UKURAN|SZ)\s*[:=-]?\s*\d+(?:[.,]\d+)?\b'),
    AttributeRule('model', 'model', r'\b[A-Z]{1,6}[-]?\d{2,8}[A-Z]?\b'),
    AttributeRule('quantity', 'quantity', r'\b\d+\s*(?:PCS|PC|PACK|SET|PAIR)\b'),
    AttributeRule('color', 'color',
                  r'\b(?:RED|BLUE|GREEN|BLACK|WHITE|YELLOW|MERAH|BIRU|HIJAU|HITAM|PUTIH|KUNING)\b'),
)

# Backwards-compatible view of the registry for the legacy scoring path.
PATTERNS = {rule.key: rule.pattern for rule in RULES}


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
    raw = str(text or '')[:MAX_TEXT].upper()
    return {key: sorted(set(re.sub(r'\s+', '', value).replace(',', '.') for value in re.findall(pattern, raw)))
            for key, pattern in PATTERNS.items() if re.search(pattern, raw)}


def compatibility(query: dict, reference: dict) -> float | None:
    common = set(query) & set(reference)
    if not common:
        return None
    scores = []
    for key in common:
        q, r = set(query[key]), set(reference[key])
        scores.append(len(q & r)/len(q | r))
    return sum(scores)/len(scores)
