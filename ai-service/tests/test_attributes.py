"""Extensible description parser: realistic fixtures, stable legacy output."""
import re
import unittest

from app.features.attributes import (
    AttributeRule, RULES, SOURCE, VERSION, compatibility, parse_attributes,
    parse_detailed, register_rule,
)


def values(records, key):
    return sorted(r['value'] for r in records if r['key'] == key)


class ParserCoverageTests(unittest.TestCase):
    def test_screwdriver_plus_and_flat_variants(self):
        plus = parse_detailed('Obeng Plus PH2 150MM gagang hitam 1pcs')
        self.assertEqual(values(plus, 'drive'), ['PH2'])
        self.assertEqual(values(plus, 'measurement'), ['150MM'])
        flat = parse_detailed('Obeng Minus FLAT 6x150mm isi 2PCS')
        self.assertEqual(values(flat, 'drive'), ['FLAT'])
        phillips = parse_detailed('PHILLIPS SCREWDRIVER PH1 100MM')
        self.assertEqual(values(phillips, 'drive'), ['PH1', 'PHILLIPS'])

    def test_measurements_with_spaces_commas_and_inches(self):
        records = parse_detailed('Kunci pas 10MM, 12 MM, kabel 1,5M, pipa 2 INCH, kuas 1" dan 1.5"')
        self.assertEqual(values(records, 'measurement'),
                         ['1"', '1.5"', '1.5M', '10MM', '12MM', '2INCH'])

    def test_sandal_sizes_need_explicit_cue(self):
        cued = parse_detailed('Sandal Swallow model 41 SIZE 40 hitam')
        self.assertEqual(values(cued, 'size'), ['SIZE40'])
        # Bare numbers are ambiguous: never guessed as sizes.
        bare = parse_detailed('Sandal Swallow 38 39 40 41 biru')
        self.assertEqual(values(bare, 'size'), [])
        self.assertEqual(values(bare, 'color'), ['BIRU'])

    def test_model_color_quantity_variants(self):
        records = parse_detailed('Kuas cat 2" JC414 putih 1 set, cat MERAH 5M')
        self.assertIn('JC414', values(records, 'model'))
        self.assertEqual(values(records, 'color'), ['MERAH', 'PUTIH'])
        self.assertEqual(values(records, 'quantity'), ['1SET'])

    def test_records_carry_raw_source_and_rule(self):
        records = parse_detailed('obeng ph2 150mm')
        by_key = {r['key']: r for r in records}
        self.assertEqual(by_key['drive']['raw'], 'ph2')
        self.assertEqual(by_key['drive']['value'], 'PH2')
        self.assertEqual(by_key['drive']['source'], SOURCE)
        self.assertEqual(by_key['drive']['rule'], 'drive')
        self.assertEqual(by_key['measurement']['raw'], '150mm')
        for record in records:
            self.assertEqual(set(record), {'key', 'value', 'raw', 'source', 'rule'})

    def test_empty_and_non_string_inputs(self):
        self.assertEqual(parse_detailed(''), [])
        self.assertEqual(parse_detailed(None), [])
        self.assertEqual(parse_attributes(''), {})
        self.assertEqual(parse_attributes(None), {})

    def test_long_input_is_bounded(self):
        records = parse_detailed('PH2 ' * 2000)
        self.assertTrue(all(r['value'] == 'PH2' for r in records))
        self.assertLessEqual(len(records), 2000)


class LegacyStabilityTests(unittest.TestCase):
    def test_legacy_output_matches_registry_derivation(self):
        texts = ['PH2 150MM 1.5" SIZE 40 BLACK 2PCS JC414', 'Obeng FLAT 6x100mm merah',
                 'Kuas 2" putih', '', 'Sandal 38 39 BIRU', 'Kabel 1,5M isi 1 SET PH1 PH3 SLOTTED']
        for text in texts:
            legacy = parse_attributes(text)
            derived = {}
            for record in parse_detailed(text):
                derived.setdefault(record['key'], set()).add(record['value'])
            self.assertEqual(legacy, {key: sorted(vals) for key, vals in derived.items()})

    def test_legacy_known_fixture_unchanged(self):
        attributes = parse_attributes('PH2 150MM 1.5" SIZE 40 BLACK 2PCS JC414')
        for field in ('drive', 'measurement', 'size', 'color', 'quantity', 'model'):
            self.assertIn(field, attributes)
        self.assertIsNone(compatibility({}, attributes))
        self.assertEqual(compatibility({'drive': ['PH1']}, {'drive': ['PH2']}), 0)


class RegistryTests(unittest.TestCase):
    def test_rules_are_ordered_unique_and_compilable(self):
        ids = [rule.id for rule in RULES]
        self.assertEqual(len(ids), len(set(ids)))
        for rule in RULES:
            re.compile(rule.pattern)
            self.assertTrue(rule.key)

    def test_register_appends_without_touching_global(self):
        before = list(RULES)
        extended = register_rule(AttributeRule('thread', 'drive', r'\bM\d+\b'))
        self.assertEqual(list(RULES), before)
        self.assertEqual(extended[-1].id, 'thread')
        self.assertIn('M6', values(parse_detailed('Baut M6 20MM', rules=extended), 'drive'))
        self.assertEqual(values(parse_detailed('Baut M6 20MM'), 'drive'), [])

    def test_register_rejects_duplicates_and_bad_patterns(self):
        with self.assertRaises(ValueError):
            register_rule(AttributeRule('drive', 'drive', r'\bx\b'))
        with self.assertRaises(ValueError):
            register_rule(AttributeRule('', 'drive', r'\bx\b'))
        with self.assertRaises(re.error):
            register_rule(AttributeRule('broken', 'broken', r'(unclosed'))

    def test_version_and_source_constants(self):
        self.assertEqual(VERSION, 'description-attributes-v1')
        self.assertEqual(SOURCE, 'description_parser')


if __name__ == '__main__':
    unittest.main()
