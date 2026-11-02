# Protokol analisis tambahan saat revisi

Ditetapkan 31 Oktober 2026 sebelum perhitungan tambahan pada tahap ini. Ini bukan preregistrasi sebelum hasil R01 diketahui. Keluaran R01 yang tersimpan telah diperiksa dalam audit sebelumnya. Tidak ada parameter retrieval, label sumber atau keanggotaan kasus yang diubah; tidak ada eksekusi ulang pencarian.

Tujuan: menambahkan CI berpasangan untuk Source Hit@1 dan Source MRR@5, serta memeriksa sensitivitas perhitungan CI utama terhadap dua spesifikasi yang tercatat.

Data: CSV final_holdout_queries_20261018_200757_4ead1cc1bf5f.csv; 600 kasus, enam konfigurasi. Bandingkan v22_context_short_swap_fast minus fixed2_context_guard. Gunakan 12 strata direction × operation, 50 kasus per strata; source roots unik sehingga unit bootstrap adalah pasangan hasil satu query/source root. Jangan memilih kasus atau strata berdasarkan efek.

Analisis tambahan utama: NumPy Generator PCG64, seed integer 20261019, 12000 resamples, resample 50 indeks query dengan replacement di setiap strata; gunakan indeks identik untuk kedua metode dan ketiga metrik; jumlahkan semua kontribusi dibagi 600. CI percentile linear 2,5 dan 97,5 persen. Ini spesifikasi implementasi rekonstruksi pada revisi, bukan bukti RNG yang dipakai saat submission.

Sensitivitas: 10000 resamples dengan NumPy PCG64 yang seed-nya diturunkan dari SHA-256 UTF-8 string TOULOUR-FINAL-ANALYSIS-20261019-R01 melalui konversi digest ke integer big endian. Karena rencana lama tidak menyebut RNG/konversi seed string, hasil ini hanya sensitivitas yang sekarang didefinisikan, bukan reproduksi persis kode asli yang belum ditemukan.

Laporkan point estimate dan kedua interval, jumlah pasangan berbeda dan p exact McNemar untuk Hit@5. CI sekunder bersifat tambahan setelah review, marginal dan tanpa koreksi multiplicity; jangan mengubah primary endpoint atau menyebut secondary endpoints sebagai temuan konfirmatori baru. Jika angka berbeda dari submission, laporkan perbedaannya dan alasan metode, jangan memilih hasil yang lebih menguntungkan.

Berkas analisis dan metadata versi software harus disertakan agar prosedur saat revisi dapat diulang.
