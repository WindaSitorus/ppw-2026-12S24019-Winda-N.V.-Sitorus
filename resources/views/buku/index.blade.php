<x-layout>
    <div class="container mt-4">
        <!-- Header & Logout -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 style="color: #4a148c; font-weight: bold;">Dasbor Perpustakaan</h2>
            <form action="/logout" method="POST">
                @csrf
                <button type="submit" class="btn btn-danger">Logout Pustakawan</button>
            </form>
        </div>

        <!-- Kartu Statistik Analitik -->
        <div class="row mb-4 text-center">
            <div class="col-md-4">
                <div class="card text-white mb-3" style="background-color: #6f42c1;">
                    <div class="card-body">
                        <h5 class="card-title">Total Judul Buku</h5>
                        <h2>{{ $totalBuku ?? 3 }}</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-white bg-success mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Total Stok Tersedia</h5>
                        <h2>{{ $totalStok ?? 6 }}</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-white bg-warning mb-3">
                    <div class="card-body">
                        <h5 class="card-title text-dark">Sedang Dipinjam</h5>
                        <h2 class="text-dark">{{ $bukuDipinjam ?? 0 }}</h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Notifikasi Sukses/Gagal Transaksi ACID -->
        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <strong>Berhasil!</strong> {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <strong>Ditolak!</strong> {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        <!-- Tabel Katalog Buku -->
        <div class="card shadow-sm">
            <div class="card-header text-white" style="background-color: #4a148c;">
                <h5 class="mb-0">Katalog Buku Tersedia</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ISBN</th>
                            <th>Judul Buku</th>
                            <th>Kategori</th>
                            <th>Penulis</th>
                            <th>Tahun</th>
                            <th>Stok</th>
                            <th>Riwayat Pinjam</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($bukus ?? [] as $buku)
                            <tr>
                                <td>{{ $buku->isbn }}</td>
                                <td><strong>{{ $buku->judul ?? $buku->judul_buku }}</strong></td>
                                <td>
                                    <span class="badge bg-secondary">{{ $buku->kategori->nama ?? 'Teknologi Informasi' }}</span>
                                </td>
                                <td>{{ $buku->penulis }}</td>
                                <td>{{ $buku->tahun_terbit ?? $buku->tahun }}</td>
                                <td>
                                    <span class="badge {{ $buku->stok > 0 ? 'bg-success' : 'bg-danger' }}">
                                        {{ $buku->stok }}
                                    </span>
                                </td>
                                <td>{{ $buku->peminjamans_count ?? 0 }} kali</td>
                                <td>
                                    <!-- Tombol Pinjam untuk Demonstrasi ACID -->
                                    <form action="/peminjaman/{{ $buku->id }}" method="POST" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-primary" {{ $buku->stok <= 0 ? 'disabled' : '' }}>
                                            Pinjam
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center py-4">Katalog buku belum tersedia.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-layout>