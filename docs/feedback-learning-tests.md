# Feedback & learning data — test coverage (Prompt 37)

Pemetaan 14 poin spec ke automated tests. Tidak ada model training di
test mana pun (semua memakai encoder mock / HTTP fake / storage fake).

| # | Poin spec | Test |
|---|---|---|
| 1 | Staff confirms correct Top-1 | `SearchFeedbackUiTest::test_quick_confirm_stores_verified_feedback_with_success_message` |
| 2 | Staff corrects wrong prediction | `SearchFeedbackUiTest::test_correction_stores_hard_negative_pair` |
| 3 | Predicted SKU tidak otomatis ground truth | `SearchFeedbackUiTest::test_prediction_without_confirmation_stores_nothing`; label training selalu `confirmed_sku` (`TrainingSamplesExportTest`) |
| 4 | Verified positive creation | `TrainingSamplesExportTest::test_correct_prediction_exports_positive_without_hard_negative` |
| 5 | Hard-negative creation | `TrainingSamplesExportTest::test_corrected_prediction_exports_hard_negative_pair` |
| 6 | Duplicate feedback | `SearchFeedbackUiTest::test_double_submit_keeps_single_verified_row` (replay → 403); `DuplicateProtectionTest::test_reencoded_same_image_is_rejected_as_exact_duplicate` |
| 7 | Invalid SKU | `SearchFeedbackUiTest::test_invalid_sku_is_rejected_before_storage`, `test_deleted_product_sku_is_rejected` |
| 8 | confirmed_search provenance | `ExportVisualReferencesTest::test_include_verified_adds_only_eligible_confirmed_search` (assert `source`, `selection_verified`, `capture_group`, `source_photo_hash`); `TrainingSamplesExportTest` (assert `source`, `verified_by/at`) |
| 9 | Unverified image tidak menjadi reference | `ExportVisualReferencesTest::test_verified_only_without_flag` |
| 10 | Quality rejection | `ReferenceQualityGuardTest` (4 guard tests) + `test_quality_rejected_row_is_excluded_from_both_exporters` (**baru**) |
| 11 | Duplicate reference rejection | `DuplicateProtectionTest` (exact/near/cross-SKU/unique); `TrainingDatasetSplitTest::test_duplicate_photo_never_leaks_across_splits`, `test_duplicate_identity_with_conflicting_groups_is_rejected` |
| 12 | Training sample verified-only | `TrainingSamplesExportTest::test_unverified_and_pending_rows_are_never_exported` |
| 13 | Exporter excludes invalid samples | `TrainingSamplesExportTest::test_missing_image_or_rejected_quality_is_not_exported`, `test_renamed_product_does_not_export_a_stale_sku_label`, `test_empty_export_succeeds_without_marking`; `ExportVisualReferencesTest::test_missing_reference_file_fails_closed` |
| 14 | Reference removal/disable | `ExportVisualReferencesTest::test_revoked_or_deleted_reference_is_excluded_from_future_exports` |

## Coverage yang ditambahkan ronde ini

- `ReferenceQualityGuardTest::test_quality_rejected_row_is_excluded_from_both_exporters`
  — loop end-to-end: konfirmasi low-blur → `reference_eligible=false` +
  status tetap `verified` → `products:export-visual --include-verified`
  tidak memuatnya sebagai referensi DAN `search:export-training`
  tidak mengekspornya sebagai sampel. Mengunci bahwa quality rejection
  memblokir SEMUA kegunaan learning, bukan hanya reference.
- Koreksi komentar basi di `test_extreme_blur_is_flagged_but_still_stored_verified`:
  baris flagged TIDAK menjadi training data (filter `reference_eligible`
  di kedua exporter); status `verified` hanya jejak audit.

## Batasan yang diketahui (bukan bagian 14 poin)

- `photo_hash` unik global: foto yang sama tidak bisa dikonfirmasi ulang
  untuk SKU lain (koreksi atas koreksi yang salah harus lewat edit/hapus
  baris, bukan konfirmasi baru). Perilaku terkunci oleh migrasi unique,
  belum ada test yang mengabadikannya.
- `search:export-training` bersemantik snapshot (setiap run mengekspor ulang
  semua baris eligible ke file BARU; `training_exported_at` hanya jejak
  audit, bukan filter inkremental). Disengaja (output wajib path baru),
  belum dikunci test agar tidak menghalangi mode inkremental di masa depan.
