<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Kategori;
use App\Models\Buku;
use App\Models\Peminjaman;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Akun uji Pustakawan & Akademik (Sesuai Modul Minggu 9)
        User::updateOrCreate(
            ['email' => 'pustakawan@del.ac.id'],
            [
                'name'     => 'Pustakawan UPT IT Del',
                'password' => Hash::make('password123'),
            ]
        );

        // 2. Kategori Buku
        $kategoris = [
            ['nama' => 'Sistem Informasi', 'deskripsi' => 'Buku referensi arsitektur enterprise, bisnis digital, dan tata kelola TI'],
            ['nama' => 'Informatika', 'deskripsi' => 'Buku pemrograman, algoritma, struktur data, dan rekayasa perangkat lunak'],
            ['nama' => 'Teknik Elektro', 'deskripsi' => 'Buku rangkaian listrik, sistem kendali, dan telekomunikasi'],
            ['nama' => 'Bioproses', 'deskripsi' => 'Buku rekayasa bioproses dan teknologi fermentasi'],
        ];

        foreach ($kategoris as $kat) {
            Kategori::firstOrCreate(['nama' => $kat['nama']], ['deskripsi' => $kat['deskripsi']]);
        }

        // 3. Sampel Buku Nyata (dengan ISBN nyata agar dapat diuji di OpenLibrary API)
        $bukuSampel = [
            [
                'isbn'         => '9780132350884',
                'judul'        => 'Clean Code: A Handbook of Agile Software Craftsmanship',
                'penulis'      => 'Robert C. Martin',
                'tahun_terbit' => 2008,
                'stok'         => 5,
                'kategori_id'  => 2,
            ],
            [
                'isbn'         => '9780201633610',
                'judul'        => 'Design Patterns: Elements of Reusable Object-Oriented Software',
                'penulis'      => 'Erich Gamma, Richard Helm, Ralph Johnson, John Vlissides',
                'tahun_terbit' => 1994,
                'stok'         => 3,
                'kategori_id'  => 2,
            ],
            [
                'isbn'         => '9780321125217',
                'judul'        => 'Domain-Driven Design: Tackling Complexity in the Heart of Software',
                'penulis'      => 'Eric Evans',
                'tahun_terbit' => 2003,
                'stok'         => 0,
                'kategori_id'  => 1,
            ],
        ];

        foreach ($bukuSampel as $b) {
            Buku::updateOrCreate(['isbn' => $b['isbn']], $b);
        }

        // 4. Tambahan data dummy buku
        if (Buku::count() < 15) {
            Buku::factory(12)->create();
        }

        // 5. Sampel data peminjaman untuk pengujian SOAP Bebas Pustaka & REST API Sirkulasi
        $firstBook = Buku::first();
        if ($firstBook) {
            Peminjaman::updateOrCreate(
                [
                    'buku_id'      => $firstBook->id,
                    'nim_peminjam' => '12S24019',
                    'status'       => 'dipinjam',
                ],
                [
                    'nama_peminjam'   => 'Winda N.V. Sitorus',
                    'tanggal_pinjam'  => now()->subDays(3)->toDateString(),
                    'tanggal_kembali' => null,
                ]
            );
        }
    }
}
