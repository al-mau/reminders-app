<?php
/**
 * SESSION LOGIN disimpan di database (tabel sessions), bukan di file + pengecekan login.
 *
 * Kenapa database? Di Vercel setiap request bisa dilayani server yang berbeda dan file sementara
 * bisa hilang, sehingga session berbasis file membuat user tiba-tiba ter-logout. Versi XAMPP
 * memakai cara yang sama agar perilaku kedua versi identik.
 *
 * Cookie session: HttpOnly (tidak bisa dibaca JavaScript), SameSite=Lax (mengurangi CSRF),
 * Secure saat diakses lewat HTTPS.
 */
require_once __DIR__ . '/koneksi.php';

class SesiDatabase implements SessionHandlerInterface
{
    // Waktu "terakhir aktif" diperbarui paling cepat tiap 5 menit bila data tidak berubah (hemat query)
    private const SEGARKAN_DETIK = 300;

    private array $dataAwal  = [];
    private array $aksesAwal = [];

    public function __construct(private PDO $pdo)
    {
    }

    /** Tabel sessions dibuat otomatis saat pertama kali dibutuhkan */
    private function buatTabel(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
            id            VARCHAR(128) NOT NULL PRIMARY KEY,
            data          MEDIUMTEXT   NOT NULL,
            last_accessed INT UNSIGNED NOT NULL,
            INDEX idx_akses (last_accessed)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function open($path, $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read($id): string|false
    {
        try {
            $stmt = $this->pdo->prepare("SELECT data, last_accessed FROM sessions WHERE id = ?");
            $stmt->execute([$id]);
        } catch (PDOException $e) {
            // Tabel belum ada (instalasi baru) -> buat lalu anggap sesi kosong
            $this->buatTabel();
            $this->dataAwal[$id] = null;
            return '';
        }
        $row = $stmt->fetch();
        $this->dataAwal[$id]  = $row ? (string) $row['data'] : null;
        $this->aksesAwal[$id] = $row ? (int) $row['last_accessed'] : 0;
        return $row ? (string) $row['data'] : '';
    }

    public function write($id, $data): bool
    {
        try {
            // Pengunjung tanpa data sesi (bot, cek otomatis) tidak perlu disimpan
            if ($data === '' && ($this->dataAwal[$id] ?? null) === null) {
                return true;
            }
            if (($this->dataAwal[$id] ?? null) === $data && time() - ($this->aksesAwal[$id] ?? 0) < self::SEGARKAN_DETIK) {
                return true;
            }
            // Bersihkan sesi tidak aktif > 24 jam sesekali
            if (random_int(1, 50) === 1) {
                $this->gc(86400);
            }
            return $this->pdo->prepare("REPLACE INTO sessions (id, data, last_accessed) VALUES (?, ?, ?)")
                ->execute([$id, $data, time()]);
        } catch (PDOException $e) {
            error_log('Gagal menyimpan sesi: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy($id): bool
    {
        try {
            return $this->pdo->prepare("DELETE FROM sessions WHERE id = ?")->execute([$id]);
        } catch (PDOException $e) {
            return false;
        }
    }

    public function gc($maxlifetime): int|false
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE last_accessed < ?");
            $stmt->execute([time() - $maxlifetime]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            return false;
        }
    }
}

/** Diakses lewat HTTPS? (di Vercel HTTPS diselesaikan proxy, tandanya ada di header X-Forwarded-Proto) */
function pakaiHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_save_handler(new SesiDatabase($pdo), true);
    session_name('PHJSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => pakaiHttps(),
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '86400'); // sesi tidak aktif 24 jam -> dihapus
    session_start();
}

// Header keamanan dasar untuk semua halaman
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    if (pakaiHttps()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/** Halaman yang butuh login: belum login -> lempar ke halaman login */
function wajibLogin(): void
{
    if (empty($_SESSION['login'])) {
        header('Location: login.php');
        exit;
    }
}
