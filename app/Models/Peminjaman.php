<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Peminjaman extends Model
{
    use HasFactory;

    protected $table = 'peminjamans';

    protected $fillable = [
        'buku_id',
        'nim_peminjam',
        'nama_peminjam',
        'tanggal_pinjam',
        'tanggal_kembali',
        'status',
    ];

    public function buku()
    {
        return $this->belongsTo(Buku::class, 'buku_id');
    }
}