<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PeminjamanResource;
use App\Models\Buku;
use App\Models\Peminjaman;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PeminjamanApiController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/v1/peminjamans (Wajib Bearer Token)
     * Menampilkan daftar transaksi peminjaman terpaginasi dengan filter
     * ?status=dipinjam|dikembalikan dan ?nim=...
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 10), 1), 50);

        $paginator = Peminjaman::query()
            ->with('buku')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('nim'), fn ($q, $nim) => $q->where('nim_peminjam', strtoupper(trim($nim))))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        return $this->successResponse(
            data: PeminjamanResource::collection($paginator->items()),
            message: 'Daftar transaksi sirkulasi peminjaman buku berhasil diambil.',
            meta: [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total_items'  => $paginator->total(),
                'total_pages'  => $paginator->lastPage(),
                'links'        => [
                    'first' => $paginator->url(1),
                    'prev'  => $paginator->previousPageUrl(),
                    'next'  => $paginator->nextPageUrl(),
                    'last'  => $paginator->url($paginator->lastPage()),
                ],
            ]
        );
    }

    /**
     * GET /api/v1/peminjamans/{id} (Wajib Bearer Token)
     * Menampilkan detail satu transaksi peminjaman
     */
    public function show(string $id): JsonResponse
    {
        $peminjaman = Peminjaman::with('buku')->find($id);

        if (!$peminjaman) {
            return $this->problemResponse(
                title: 'Resource Not Found',
                detail: "Transaksi peminjaman dengan ID '{$id}' tidak ditemukan.",
                status: 404,
                errorSlug: 'peminjaman-not-found'
            );
        }

        return $this->successResponse(
            data: new PeminjamanResource($peminjaman),
            message: 'Detail transaksi peminjaman berhasil diambil.'
        );
    }

    /**
     * POST /api/v1/peminjamans (Wajib Bearer Token)
     * Mencatat peminjaman buku baru via JSON (buku_id, nim_peminjam, nama_peminjam, durasi_hari)
     * Wajib dibungkus DB::transaction() dengan lockForUpdate() pada baris buku
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'buku_id'       => 'required|integer',
            'nim_peminjam'  => 'required|string|max:20',
            'nama_peminjam' => 'required|string|max:150',
            'durasi_hari'   => 'nullable|integer|min:1|max:30',
        ]);

        return DB::transaction(function () use ($validated) {
            // 1. Kunci baris buku secara eksklusif (SELECT ... FOR UPDATE)
            $buku = Buku::where('id', $validated['buku_id'])->lockForUpdate()->first();

            if (!$buku) {
                return $this->problemResponse(
                    title: 'Resource Not Found',
                    detail: "Buku dengan ID '{$validated['buku_id']}' tidak ditemukan.",
                    status: 404,
                    errorSlug: 'buku-not-found'
                );
            }

            $nim = strtoupper(trim($validated['nim_peminjam']));

            // 2. Jika mahasiswa masih meminjam buku yang sama dan belum dikembalikan -> 409 Conflict
            $alreadyBorrowed = Peminjaman::where('buku_id', $buku->id)
                ->where('nim_peminjam', $nim)
                ->where('status', 'dipinjam')
                ->exists();

            if ($alreadyBorrowed) {
                return $this->problemResponse(
                    title: 'Duplicate Active Loan Conflict',
                    detail: "Mahasiswa dengan NIM '{$nim}' masih meminjam buku '{$buku->judul}' dan belum dikembalikan.",
                    status: 409,
                    errorSlug: 'duplicate-active-loan'
                );
            }

            // 3. Jika stok < 1, batalkan dan kembalikan 422 Unprocessable Content ("Stok buku sedang habis")
            if ($buku->stok < 1) {
                return $this->problemResponse(
                    title: 'Book Stock Exhausted',
                    detail: 'Stok buku sedang habis',
                    status: 422,
                    errorSlug: 'stok-buku-habis'
                );
            }

            // 4. Kurangi stok (-1) dan simpan peminjaman baru secara atomik
            $buku->decrement('stok');

            $peminjaman = Peminjaman::create([
                'buku_id'         => $buku->id,
                'nim_peminjam'    => $nim,
                'nama_peminjam'   => $validated['nama_peminjam'],
                'tanggal_pinjam'  => now()->toDateString(),
                'tanggal_kembali' => null,
                'status'          => 'dipinjam',
            ]);

            $peminjaman->load('buku');

            return $this->createdResponse(
                data: new PeminjamanResource($peminjaman),
                locationUrl: url("/api/v1/peminjamans/{$peminjaman->id}"),
                message: "Peminjaman buku '{$buku->judul}' berhasil dicatat. Sisa stok kini: {$buku->fresh()->stok}."
            );
        });
    }

    /**
     * PATCH /api/v1/peminjamans/{id}/kembalikan (Wajib Bearer Token)
     * Mengembalikan buku di dalam DB::transaction() + lockForUpdate().
     * Jika sudah berstatus 'dikembalikan', tolak dengan 409 Conflict.
     * Jika valid, ubah status menjadi 'dikembalikan', isi tanggal_kembali, tambah stok buku (+1), dan kembalikan 200 OK.
     */
    public function kembalikan(string $id): JsonResponse
    {
        return DB::transaction(function () use ($id) {
            $peminjaman = Peminjaman::where('id', $id)->lockForUpdate()->first();

            if (!$peminjaman) {
                return $this->problemResponse(
                    title: 'Resource Not Found',
                    detail: "Data peminjaman dengan ID '{$id}' tidak ditemukan.",
                    status: 404,
                    errorSlug: 'peminjaman-not-found'
                );
            }

            if ($peminjaman->status === 'dikembalikan') {
                return $this->problemResponse(
                    title: 'Conflict — Book Already Returned',
                    detail: "Transaksi peminjaman #{$peminjaman->id} sudah berstatus 'dikembalikan' pada tanggal {$peminjaman->tanggal_kembali}.",
                    status: 409,
                    errorSlug: 'loan-already-returned'
                );
            }

            $buku = Buku::where('id', $peminjaman->buku_id)->lockForUpdate()->first();

            $peminjaman->update([
                'status'          => 'dikembalikan',
                'tanggal_kembali' => now()->toDateString(),
            ]);

            if ($buku) {
                $buku->increment('stok');
            }

            $peminjaman->load('buku');

            return $this->successResponse(
                data: new PeminjamanResource($peminjaman),
                message: "Buku berhasil dikembalikan. Stok buku kini bertambah menjadi {$buku?->fresh()?->stok}."
            );
        });
    }
}
