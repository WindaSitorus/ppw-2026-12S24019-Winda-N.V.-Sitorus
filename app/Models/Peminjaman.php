<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Peminjaman extends Model
{
    use HasFactory;

    // Tambahkan baris ini agar Laravel tidak salah cari tabel
    protected $table = 'peminjamans';

    // ... (biarkan kode fillable atau relasi belongsTo yang sudah ada di bawahnya) ...
}