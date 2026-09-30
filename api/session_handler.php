<?php
require_once __DIR__ . '/koneksi.php';

class DatabaseSessionHandler implements SessionHandlerInterface {
    private $pdo;

    public function __construct($pdoInstance) {
        $this->pdo = $pdoInstance;
    }

    public function open($savePath, $sessionName): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    public function read($id): string|false {
        try {
            $stmt = $this->pdo->prepare("SELECT data FROM sessions WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ? (string)$row['data'] : '';
        } catch (Exception $e) {
            return '';
        }
    }

    public function write($id, $data): bool {
        try {
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

    public function destroy($id): bool {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE id = :id");
            return $stmt->execute([':id' => $id]);
        } catch (Exception $e) {
            return false;
        }
    }

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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
