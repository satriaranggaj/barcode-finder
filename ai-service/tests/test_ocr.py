"""Modular OCR: structured words, mocked engine, graceful degradation."""
import unittest
from unittest.mock import patch
from PIL import Image

from app.features.ocr import (
    DisabledOCR, MAX_TEXT_LENGTH, MAX_WORDS, OcrResult, TesseractOCR, normalize_token,
)

HEADER = 'level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext'


def tsv(*rows):
    return '\n'.join([HEADER, *rows])


def word(text, conf='96', left='10', top='20', width='60', height='15', level='5'):
    return f'{level}\t1\t1\t1\t1\t1\t{left}\t{top}\t{width}\t{height}\t{conf}\t{text}'


def run_with(stdout):
    from subprocess import CompletedProcess
    return CompletedProcess(args=['tesseract'], returncode=0, stdout=stdout, stderr='')


class OcrPipelineTests(unittest.TestCase):
    def image(self):
        return Image.new('RGB', (200, 100), 'white')

    def test_markings_parse_with_tokens_confidence_and_boxes(self):
        ocr = TesseractOCR('tesseract', 80)
        stdout = tsv(word('PH2', '96'), word('150MM', '91'), word('1.5"', '88'))
        with patch('subprocess.run', return_value=run_with(stdout)):
            result = ocr.extract_words(self.image())
        self.assertIsInstance(result, OcrResult)
        self.assertEqual(result.engine, 'tesseract')
        self.assertEqual(result.texts, ['PH2', '150MM', '1.5"'])
        self.assertEqual(result.tokens, ['PH2', '150MM', '1.5"'])
        self.assertEqual([w.confidence for w in result.words], [96.0, 91.0, 88.0])
        box = result.words[0].box
        # 200x100 white image is downscaled? No: 200x100 < 1024, size unchanged.
        self.assertAlmostEqual(box[0], 10 / 200)
        self.assertAlmostEqual(box[1], 20 / 100)
        self.assertAlmostEqual(box[2], 70 / 200)
        self.assertAlmostEqual(box[3], 35 / 100)

    def test_low_confidence_and_non_word_levels_never_pass(self):
        ocr = TesseractOCR('tesseract', 80)
        stdout = tsv(word('PH1', '12'), word('', '95'), word('line-text', '95', level='4'),
                     word('GOOD', '80'))
        with patch('subprocess.run', return_value=run_with(stdout)):
            result = ocr.extract_words(self.image())
        self.assertEqual(result.texts, ['GOOD'])

    def test_extract_text_stays_backward_compatible(self):
        ocr = TesseractOCR('tesseract', 80)
        stdout = tsv(word('10MM', '90'))
        with patch('subprocess.run', return_value=run_with(stdout)):
            self.assertEqual(ocr.extract_text(self.image()), ['10MM'])

    def test_caps_apply_to_word_count_and_text_length(self):
        ocr = TesseractOCR('tesseract', 0)
        rows = [word(f'W{i}', '50') for i in range(MAX_WORDS + 5)]
        rows.append(word('X' * (MAX_TEXT_LENGTH + 40), '50'))
        with patch('subprocess.run', return_value=run_with(tsv(*rows))):
            result = ocr.extract_words(self.image())
        self.assertEqual(len(result.words), MAX_WORDS)
        self.assertTrue(all(len(w.text) <= MAX_TEXT_LENGTH for w in result.words))

    def test_missing_engine_and_failures_degrade_to_empty(self):
        ocr = TesseractOCR('nonexistent-lensku-ocr')
        self.assertEqual(ocr.extract_words(self.image()).words, ())
        self.assertEqual(ocr.extract_text(self.image()), [])
        with patch('subprocess.run', side_effect=OSError('no binary')):
            self.assertEqual(TesseractOCR().extract_words(self.image()).words, ())
        from subprocess import CalledProcessError
        with patch('subprocess.run', side_effect=CalledProcessError(1, 'tesseract')):
            self.assertEqual(TesseractOCR().extract_text(self.image()), [])
        with patch('subprocess.run', return_value=run_with('not\ta\table')):
            self.assertEqual(TesseractOCR().extract_text(self.image()), [])

    def test_malformed_rows_and_boxes_are_skipped_safely(self):
        ocr = TesseractOCR('tesseract', 0)
        stdout = tsv(word('OK', '50'), word('NOCONF', 'high'),
                     word('NOBOX', '50', left='x'), word('OUT', '50', left='-5'))
        with patch('subprocess.run', return_value=run_with(stdout)):
            result = ocr.extract_words(self.image())
        boxes = {w.text: w.box for w in result.words}
        self.assertIn('OK', boxes)
        # Unparseable confidence drops the word; unparseable geometry keeps the
        # word with no box rather than discarding valid text.
        self.assertNotIn('NOCONF', boxes)
        self.assertIsNone(boxes['NOBOX'])
        self.assertIsNone(boxes['OUT'])

    def test_normalize_token_and_disabled_extractor(self):
        self.assertEqual(normalize_token('  ph2\n'), 'PH2')
        self.assertEqual(normalize_token(''), '')
        self.assertEqual(DisabledOCR().extract_words(self.image()), OcrResult())
        self.assertEqual(DisabledOCR().extract_text(self.image()), [])

    def test_nonfinite_confidence_and_geometry_never_enter_evidence(self):
        result = TesseractOCR().parse_tsv(tsv(word('NAN', 'nan'), word('INF', 'inf'),
            word('OVER', '101'), word('OK', '90', left='nan')), 200, 100)
        self.assertEqual(result.texts, ['OK'])
        self.assertIsNone(result.words[0].box)


if __name__ == '__main__':
    unittest.main()
