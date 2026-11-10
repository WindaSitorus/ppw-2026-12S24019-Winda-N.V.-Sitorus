<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BukuResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $totalPeminjaman = $this->whenCounted('peminjamans', fn () => $this->peminjamans_count, 0);
        $isAvailable     = (int) $this->stok > 0;

        // Bangun kontrol Hypermedia HATEOAS (Richardson Maturity Model Level 3)
        $links = [
            'self' => [
                'href'   => url("/api/v1/bukus/{$this->id}"),
                'method' => 'GET',
            ],
            'referensi_eksternal' => [
                'href'   => url("/api/v1/bukus/{$this->id}/referensi-eksternal"),
                'method' => 'GET',
            ],
            'update_full' => [
                'href'   => url("/api/v1/bukus/{$this->id}"),
                'method' => 'PUT',
            ],
            'update_partial' => [
                'href'   => url("/api/v1/bukus/{$this->id}"),
                'method' => 'PATCH',
            ],
            'delete' => [
                'href'   => url("/api/v1/bukus/{$this->id}"),
                'method' => 'DELETE',
            ],
        ];

        // HATEOAS Dinamis: Link pinjam_buku hanya muncul di dalam _links apabila stok > 0
        if ($isAvailable) {
            $links['pinjam_buku'] = [
                'href'   => url('/api/v1/peminjamans'),
                'method' => 'POST',
            ];
        }

        return [
            'id'                  => $this->id,
            'isbn'                => $this->isbn,
            'judul'               => $this->judul,
            'penulis'             => $this->penulis,
            'tahun_terbit'        => $this->tahun_terbit !== null ? (int) $this->tahun_terbit : null,
            'stok'                => (int) $this->stok,
            'status_ketersediaan' => $isAvailable ? 'TERSEDIA' : 'HABIS',
            'kategori_id'         => (int) $this->kategori_id,
            'kategori'            => $this->whenLoaded('kategori', fn () => [
                'id'        => $this->kategori->id,
                'nama'      => $this->kategori->nama,
                'deskripsi' => $this->kategori->deskripsi,
            ]),
            'total_peminjaman'    => $totalPeminjaman,
            'riwayat_peminjaman'  => PeminjamanResource::collection($this->whenLoaded('peminjamans')),
            'updated_at'          => $this->updated_at?->toIso8601String(),
            '_links'              => $links,
        ];
    }
}
