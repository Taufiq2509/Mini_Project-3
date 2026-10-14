# Skenario Pengujian Web 4

| No | Skenario | Langkah | Hasil yang diharapkan |
|---|---|---|---|
| 1 | Login valid | Login admin/password | Dashboard terbuka |
| 2 | Login salah | Masukkan password salah | Pesan kesalahan, tidak login |
| 3 | Role staff | Login staff/password, buka master data | Form edit/hapus tidak tampil; endpoint perubahan ditolak |
| 4 | SKU unik | Tambah produk memakai SKU yang sudah ada | Data ditolak oleh unique constraint |
| 5 | Validasi jumlah | Kirim jumlah 0 atau teks | Form ditolak |
| 6 | Barang masuk | Tambah 5 unit produk | Stok bertambah 5 dan histori tercatat |
| 7 | Barang keluar valid | Kurangi stok dengan jumlah yang tersedia | Stok berkurang dan histori tercatat |
| 8 | Barang keluar berlebih | Keluarkan lebih banyak dari stok | Transaksi ditolak dan stok tidak berubah |
| 9 | Search/filter/sort/page | Cari SKU, pilih kategori, urutkan, pindah halaman | Parameter pencarian tetap dipertahankan |
| 10 | XSS output | Isi deskripsi dengan tag HTML | Ditampilkan sebagai teks, bukan dieksekusi |
| 11 | Foreign key | Hapus kategori yang masih dipakai | Penghapusan ditolak database |
| 12 | Dashboard | Catat transaksi dan buka dashboard | Ringkasan berubah sesuai data |
