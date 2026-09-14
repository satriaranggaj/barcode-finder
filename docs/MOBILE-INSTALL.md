# Instalasi Lensku di ponsel

Lensku dapat dipasang sebagai web app melalui manifest, tanpa APK. Buka situs
melalui HTTPS lalu tekan **Instal** pada header.

- Android: tombol membuka prompt browser jika tersedia. Jika belum tersedia,
  petunjuk menjelaskan menu **Instal aplikasi / Tambahkan ke layar utama**.
- iPhone/iPad: tombol menampilkan petunjuk **Bagikan → Tambah ke Layar Utama → Tambah**.
  Gunakan Safari jika menu tersebut tidak tersedia pada browser yang digunakan.
- Tombol disembunyikan ketika Lensku dibuka dalam mode aplikasi atau setelah
  instalasi diterima. Browser menentukan ketersediaan prompt instalasi.

Pencarian, login, dan upload tetap membutuhkan internet. Tidak ada service worker,
cache halaman privat, atau antrean upload offline yang ditambahkan. Manifest
memulai aplikasi di `/` dan memakai ikon lokal di `public/favicon_io`.

Validasi perangkat: buka situs HTTPS di Chrome Android dan Safari iPhone, pasang,
buka ikon Lensku, periksa mode standalone, lalu coba pencarian dan upload foto.
Pengujian otomatis tidak menggantikan verifikasi instalasi pada perangkat nyata.

Referensi: https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Making_PWAs_installable
