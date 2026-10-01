<?php
/**
 * Penyimpanan SESSION LOGIN di database (tabel sessions), bukan di file.
 *
 * Kenapa? Di Vercel setiap request bisa dilayani server yang berbeda dan
 * file sementara bisa hilang, sehingga session berbasis file membuat user
 * tiba-tiba ter-logout. Dengan database, status login tetap tersimpan.
 *
 * Cara pakai: require_once file ini di halaman yang butuh login; session
 * otomatis dimulai (session_start) di bagian bawah file.
 */
require_once __DIR__ . '/koneksi.php';

class DatabaseSessionHandler implements SessionHandlerInterface {
    private $pdo;

    // Data sesi saat dibaca, untuk melewati penulisan ulang jika tidak ada perubahan
    private $dataAwal = [];
    private $aksesAwal = [];
    // Waktu "terakhir aktif" sesi diperbarui paling cepat tiap 5 menit (hemat query)
    private const SEGARKAN_DETIK = 300;

    public function __construct($pdoInstance) {
        $this->pdo = $pdoInstance;
    }

    public function open($savePath, $sessionName): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    // Dipanggil PHP saat session_start(): ambil data sesi dari tabel sessions
    public function read($id): string|false {
        try {
            $stmt = $this->pdo->prepare("SELECT data, last_accessed FROM sessions WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->dataAwal[$id]  = $row ? (string) $row['data'] : null;
            $this->aksesAwal[$id] = $row ? (int) $row['last_accessed'] : 0;

            return $row ? (string)$row['data'] : '';
        } catch (Exception $e) {
            return '';
        }
    }

    // Dipanggil PHP di akhir request: simpan data sesi ke tabel sessions
    public function write($id, $data): bool {
        try {
            // Lewati jika data tidak berubah dan baru diperbarui < 5 menit lalu (hemat 1x ke DB)
            if (($this->dataAwal[$id] ?? null) === $data && time() - ($this->aksesAwal[$id] ?? 0) < self::SEGARKAN_DETIK) {
                return true;
            }
            $access = time();
            $stmt = $this->pdo->prepare("REPLACE INTO sessions (id, data, last_accessed) VALUES (:id, :data, :access)");
            return $stmt->execute([
                ':id' => $id,
                ':data' => $data,
                ':access' => $access
            ]);
        } catch (Exception $e) {
            return false;
        }
    }

    // Dipanggil saat logout (session_destroy): hapus baris sesi
    public function destroy($id): bool {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE id = :id");
            return $stmt->execute([':id' => $id]);
        } catch (Exception $e) {
            return false;
        }
    }

    // Pembersihan otomatis sesi yang sudah tidak aktif > gc_maxlifetime (24 jam)
    public function gc($maxlifetime): int|false {
        try {
            $old = time() - $maxlifetime;
            $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE last_accessed < :old");
            $stmt->execute([':old' => $old]);
            return $stmt->rowCount();
        } catch (Exception $e) {
            return false;
        }
    }
}

// Inisialisasi Database Session Handler dengan PDO dari koneksi.php
if (isset($pdo)) {
    $handler = new DatabaseSessionHandler($pdo);
    session_set_save_handler($handler, true);
}

// Pengaturan cookie sesi yang aman, lalu mulai session:
//  - secure   : cookie hanya dikirim lewat HTTPS
//  - httponly : cookie tidak bisa dibaca JavaScript (mencegah pencurian sesi)
//  - samesite : cookie tidak ikut terkirim dari situs lain (bantu cegah CSRF)
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '86400'); // sesi tidak aktif 24 jam -> dihapus

    session_start();
}

