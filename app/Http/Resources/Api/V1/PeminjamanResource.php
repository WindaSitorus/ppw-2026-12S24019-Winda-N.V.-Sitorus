<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PeminjamanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $links = [
            'self' => [
                'href'   => url("/api/v1/peminjamans/{$this->id}"),
                'method' => 'GET',
            ],
            'buku' => [
                'href'   => url("/api/v1/bukus/{$this->buku_id}"),
                'method' => 'GET',
            ],
        ];

        // HATEOAS Dinamis: Link kembalikan_buku (PATCH) hanya muncul apabila status === 'dipinjam'
        if ($this->status === 'dipinjam') {
            $links['kembalikan_buku'] = [
                'href'   => url("/api/v1/peminjamans/{$this->id}/kembalikan"),
                'method' => 'PATCH',
            ];
        }

        return [
            'id'              => $this->id,
            'buku_id'         => (int) $this->buku_id,
            'buku_judul'      => $this->whenLoaded('buku', fn () => $this->buku->judul),
            'buku_isbn'       => $this->whenLoaded('buku', fn () => $this->buku->isbn),
            'nim_peminjam'    => $this->nim_peminjam,
            'nama_peminjam'   => $this->nama_peminjam,
            'tanggal_pinjam'  => $this->tanggal_pinjam,
            'tanggal_kembali' => $this->tanggal_kembali,
            'status'          => $this->status,
            'created_at'      => $this->created_at?->toIso8601String(),
            '_links'          => $links,
        ];
    }
}
