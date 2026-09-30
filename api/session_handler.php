<?php
require_once 'koneksi.php';

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
        $stmt = $this->pdo->prepare("SELECT data FROM sessions WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $row['data'] : '';
    }

    public function write($id, $data): bool {
        $access = time();
        $stmt = $this->pdo->prepare("REPLACE INTO sessions (id, data, last_accessed) VALUES (:id, :data, :access)");
        return $stmt->execute([
            ':id' => $id,
            ':data' => $data,
            ':access' => $access
        ]);
    }

    public function destroy($id): bool {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function gc($maxlifetime): int|false {
        $old = time() - $maxlifetime;
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE last_accessed < :old");
        $stmt->execute([':old' => $old]);
        return $stmt->rowCount();
    }
}

// Inisialisasi Database Session Handler
if (isset($pdo)) {
    $handler = new DatabaseSessionHandler($pdo);
    session_set_save_handler($handler, true);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
