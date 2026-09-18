@props(['image' => null, 'crop' => null, 'form' => null, 'source' => null])
<div data-object-selection data-form="{{ $form }}" data-image-url="{{ $image }}" data-initial-crop='@json($crop)' data-initial-source="{{ $source }}" data-select-url="{{ route('object-selection.propose') }}" class="mt-4 rounded-xl border border-[#ead9b8] bg-[#fff8d6] p-3 text-[#543019]" @if (!$image) hidden @endif>
    <p class="mb-2 text-sm font-bold">Pilih area barang</p>
    <p data-selection-status role="status" class="mb-2 text-xs">Kotak menyesuaikan otomatis. Geser untuk memindah, tarik sudut atau tepi untuk mengubah ukuran.</p>
    <div data-preview class="relative min-h-[200px] overflow-hidden rounded-lg"></div>
    <input type="hidden" name="crop_json" data-result-input value='@json($crop)' @if ($form) form="{{ $form }}" @endif>
    <input type="hidden" name="selection_source" data-source-input value="{{ $crop ? 'manual' : 'full' }}" @if ($form) form="{{ $form }}" @endif>
</div>
