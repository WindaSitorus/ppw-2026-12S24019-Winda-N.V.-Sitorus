<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BukuResource;
use App\Models\Buku;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class BukuApiController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/v1/bukus (Publik)
     * Menampilkan koleksi buku terpaginasi lengkap dengan blok meta,
     * mendukung pencarian ?search=... (judul/penulis/ISBN),
     * filter ?kategori_id=..., filter ketersediaan ?tersedia=1 (stok > 0),
     * serta pengurutan ?sort=... Bebas dari N+1 Query Problem.
     */
    public function index(Request $request): JsonResponse
    {
        $allowedSorts = ['id', 'isbn', 'judul', 'penulis', 'tahun_terbit', 'stok', 'created_at'];
        $sortParam    = (string) $request->query('sort', 'judul');
        $sortDir      = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        if (str_starts_with($sortParam, '-')) {
            $sortDir   = 'desc';
            $sortParam = substr($sortParam, 1);
        }

        $sortBy  = in_array($sortParam, $allowedSorts, true) ? $sortParam : 'judul';
        $perPage = min(max((int) $request->query('per_page', 5), 1), 50);

        $paginator = Buku::query()
            ->with('kategori')
            ->withCount('peminjamans')
            ->when($request->query('search'), function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('judul', 'like', "%{$search}%")
                        ->orWhere('penulis', 'like', "%{$search}%")
                        ->orWhere('isbn', 'like', "%{$search}%");
                });
            })
            ->when($request->query('kategori_id'), fn ($q, $katId) => $q->where('kategori_id', (int) $katId))
            ->when($request->has('tersedia') && $request->boolean('tersedia'), fn ($q) => $q->where('stok', '>', 0))
            ->orderBy($sortBy, $sortDir)
            ->paginate($perPage)
            ->withQueryString();

        return $this->successResponse(
            data: BukuResource::collection($paginator->items()),
            message: 'Daftar koleksi katalog buku perpustakaan berhasil diambil.',
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
     * GET /api/v1/bukus/{id} (Publik)
     * Menampilkan detail satu buku beserta objek kategori dan daftar riwayat peminjamannya
     */
    public function show(string $id): JsonResponse
    {
        $buku = Buku::with(['kategori', 'peminjamans'])
            ->withCount('peminjamans')
            ->where('id', $id)
            ->orWhere('isbn', $id)
            ->first();

        if (!$buku) {
            return $this->problemResponse(
                title: 'Resource Not Found',
                detail: "Buku dengan pengenal '{$id}' tidak ditemukan di dalam katalog perpustakaan.",
                status: 404,
                errorSlug: 'buku-not-found'
            );
        }

        return $this->successResponse(
            data: new BukuResource($buku),
            message: 'Detail buku beserta kategori dan riwayat peminjaman berhasil diambil.'
        );
    }

    /**
     * POST /api/v1/bukus (Wajib Bearer Token)
     * Menyimpan buku baru -> 201 Created + Header Location: /api/v1/bukus/{id}
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'isbn'         => 'required|string|max:20|unique:bukus,isbn',
            'judul'        => 'required|string|max:150',
            'penulis'      => 'required|string|max:100',
            'tahun_terbit' => 'required|integer|digits:4|min:1900|max:' . (date('Y') + 1),
            'stok'         => 'required|integer|min:0|max:1000',
            'kategori_id'  => 'required|integer|exists:kategoris,id',
        ]);

        $validated['isbn'] = trim($validated['isbn']);
        $buku = Buku::create($validated);
        $buku->load('kategori')->loadCount('peminjamans');

        return $this->createdResponse(
            data: new BukuResource($buku),
            locationUrl: url("/api/v1/bukus/{$buku->id}"),
            message: 'Buku baru berhasil ditambahkan ke dalam katalog perpustakaan.'
        );
    }

    /**
     * PUT / PATCH /api/v1/bukus/{id} (Wajib Bearer Token)
     * Membuktikan perbedaan nyata antara penggantian penuh (PUT) dan modifikasi parsial (PATCH)
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $buku = Buku::find($id);

        if (!$buku) {
            return $this->problemResponse(
                title: 'Resource Not Found',
                detail: "Buku dengan ID '{$id}' tidak ditemukan.",
                status: 404,
                errorSlug: 'buku-not-found'
            );
        }

        $isPut = $request->isMethod('put');

        if ($isPut) {
            // SEMANTIK PUT (Full Replacement): Seluruh atribut wajib dikirimkan secara utuh
            $validated = $request->validate([
                'isbn'         => "required|string|max:20|unique:bukus,isbn,{$buku->id}",
                'judul'        => 'required|string|max:150',
                'penulis'      => 'required|string|max:100',
                'tahun_terbit' => 'required|integer|digits:4|min:1900|max:' . (date('Y') + 1),
                'stok'         => 'required|integer|min:0|max:1000',
                'kategori_id'  => 'required|integer|exists:kategoris,id',
            ]);
            $validated['isbn'] = trim($validated['isbn']);
        } else {
            // SEMANTIK PATCH (Partial Update): Hanya memvalidasi & memperbarui atribut yang dikirim
            $validated = $request->validate([
                'isbn'         => "sometimes|required|string|max:20|unique:bukus,isbn,{$buku->id}",
                'judul'        => 'sometimes|required|string|max:150',
                'penulis'      => 'sometimes|required|string|max:100',
                'tahun_terbit' => 'sometimes|required|integer|digits:4|min:1900|max:' . (date('Y') + 1),
                'stok'         => 'sometimes|required|integer|min:0|max:1000',
                'kategori_id'  => 'sometimes|required|integer|exists:kategoris,id',
            ]);

            if (empty($validated)) {
                return $this->problemResponse(
                    title: 'Bad Request',
                    detail: 'Permintaan PATCH wajib menyertakan minimal satu atribut buku yang ingin diperbarui.',
                    status: 400,
                    errorSlug: 'empty-patch-payload'
                );
            }

            if (isset($validated['isbn'])) {
                $validated['isbn'] = trim($validated['isbn']);
            }
        }

        $buku->update($validated);
        $buku->load('kategori')->loadCount('peminjamans');

        return $this->successResponse(
            data: new BukuResource($buku),
            message: $isPut
                ? 'Seluruh representasi data buku berhasil diganti secara utuh (PUT).'
                : 'Atribut buku berhasil diperbarui secara parsial (PATCH).'
        );
    }

    /**
     * DELETE /api/v1/bukus/{id} (Wajib Bearer Token)
     * Menolak penghapusan dengan 409 Conflict jika buku sedang memiliki peminjaman aktif (status = 'dipinjam'),
     * dan mengembalikan 204 No Content jika berhasil dihapus.
     */
    public function destroy(string $id): JsonResponse
    {
        $buku = Buku::find($id);

        if (!$buku) {
            return $this->problemResponse(
                title: 'Resource Not Found',
                detail: "Buku dengan ID '{$id}' tidak ditemukan.",
                status: 404,
                errorSlug: 'buku-not-found'
            );
        }

        $activeLoansCount = $buku->peminjamans()->where('status', 'dipinjam')->count();

        if ($activeLoansCount > 0) {
            return $this->problemResponse(
                title: 'Conflict — Book Has Active Loans',
                detail: "Buku '{$buku->judul}' tidak dapat dihapus karena sedang dipinjam pada {$activeLoansCount} transaksi aktif.",
                status: 409,
                errorSlug: 'book-has-active-loans'
            );
        }

        $buku->delete();

        return $this->noContentResponse();
    }

    /**
     * GET /api/v1/bukus/{id}/referensi-eksternal (Publik - REST Service Consumer)
     * Memanggil OpenLibrary REST API berdasarkan ISBN menggunakan Http::timeout(8)->get(...)
     */
    public function referensiEksternal(string $id): JsonResponse
    {
        $buku = Buku::where('id', $id)->orWhere('isbn', $id)->first();

        if (!$buku) {
            return $this->problemResponse(
                title: 'Resource Not Found',
                detail: "Buku dengan pengenal '{$id}' tidak ditemukan di basis data lokal.",
                status: 404,
                errorSlug: 'buku-not-found'
            );
        }

        $cleanIsbn = preg_replace('/[^0-9Xx]/', '', (string) $buku->isbn);
        $bibKey    = "ISBN:{$cleanIsbn}";

        try {
            $response = Http::acceptJson()
                ->timeout(8)
                ->get('https://openlibrary.org/api/books', [
                    'bibkeys' => $bibKey,
                    'format'  => 'json',
                    'jscmd'   => 'data',
                ]);

            if ($response->failed()) {
                return $this->problemResponse(
                    title: 'Bad Gateway — External Service Error',
                    detail: 'Gagal berkomunikasi dengan layanan eksternal OpenLibrary REST API.',
                    status: 502,
                    errorSlug: 'external-api-error'
                );
            }

            $payload = $response->json();
            $extData = $payload[$bibKey] ?? null;

            return $this->successResponse(
                data: [
                    'buku_lokal' => [
                        'id'      => $buku->id,
                        'isbn'    => $buku->isbn,
                        'judul'   => $buku->judul,
                        'penulis' => $buku->penulis,
                        'stok'    => (int) $buku->stok,
                    ],
                    'openlibrary_metadata' => $extData ? [
                        'found'           => true,
                        'title'           => $extData['title'] ?? $buku->judul,
                        'number_of_pages' => $extData['number_of_pages'] ?? null,
                        'publish_date'    => $extData['publish_date'] ?? (string) $buku->tahun_terbit,
                        'publishers'      => collect($extData['publishers'] ?? [])->pluck('name')->values(),
                        'authors'         => collect($extData['authors'] ?? [])->pluck('name')->values(),
                        'info_url'        => $extData['url'] ?? "https://openlibrary.org/isbn/{$cleanIsbn}",
                        'cover'           => $extData['cover'] ?? null,
                    ] : [
                        'found'    => false,
                        'note'     => "Metadata ISBN '{$cleanIsbn}' belum tercatat di katalog publik OpenLibrary.",
                        'info_url' => "https://openlibrary.org/isbn/{$cleanIsbn}",
                    ],
                ],
                message: 'Pengayaan metadata buku dari layanan eksternal OpenLibrary API berhasil diproses.'
            );
        } catch (\Throwable $e) {
            // Penanganan aman jika koneksi timeout atau offline
            return $this->successResponse(
                data: [
                    'buku_lokal' => [
                        'id'      => $buku->id,
                        'isbn'    => $buku->isbn,
                        'judul'   => $buku->judul,
                        'penulis' => $buku->penulis,
                        'stok'    => (int) $buku->stok,
                    ],
                    'openlibrary_metadata' => [
                        'found'           => false,
                        'fallback_status' => 'offline_or_timeout',
                        'note'            => 'Layanan eksternal OpenLibrary tidak dapat dijangkau saat ini (timeout/offline). Menampilkan data lokal.',
                        'info_url'        => "https://openlibrary.org/isbn/{$cleanIsbn}",
                    ],
                ],
                message: 'Menampilkan data buku lokal (fallback aman karena layanan OpenLibrary eksternal timeout).'
            );
        }
    }
}
