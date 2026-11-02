<?php
/**
 * Database Connection Singleton Class menggunakan PDO
 */
class Database {
    private static $instance = null;
    private $conn;

    private $host; // Reviewer adapter: configured from TOULOUR_REPRO_DB_HOST
    private $db; // Reviewer adapter: configured from TOULOUR_REPRO_DB_NAME
    private $user; // Reviewer adapter: configured from TOULOUR_REPRO_DB_USER
    private $pass; // Reviewer adapter: configured from TOULOUR_REPRO_DB_PASSWORD
    private $charset = 'utf8mb4';

    private function __construct() {
        $this->host = getenv('TOULOUR_REPRO_DB_HOST');
        $this->db = getenv('TOULOUR_REPRO_DB_NAME');
        $this->user = getenv('TOULOUR_REPRO_DB_USER');
        $this->pass = getenv('TOULOUR_REPRO_DB_PASSWORD');
        if ($this->host === false || $this->db !== 'kamus_tolour_research' || $this->user === false || $this->pass === false) {
            throw new RuntimeException('Set all TOULOUR_REPRO_DB_* values for an isolated research database.');
        }
        $this->connect();
    }

    private function connect() {
        $dsn = "mysql:host={$this->host};dbname={$this->db};charset={$this->charset}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
        ];
        try {
            $this->conn = new PDO($dsn, $this->user, $this->pass, $options);
            // Database memakai DATETIME. Samakan sesi MariaDB dengan WITA agar
            // timestamp audit tidak mengikuti timezone bawaan server/XAMPP.
            $this->conn->exec("SET time_zone = '+08:00'");
        } catch (PDOException $e) {
            // Catat detail teknis hanya di server log; jangan tampilkan pesan DB ke pengguna.
            error_log('Database connection failed: ' . $e->getMessage());
            http_response_code(500);
            exit('Terjadi kesalahan pada layanan database. Silakan coba lagi nanti.');
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance->conn;
    }

    /**
     * Membuat PDO baru untuk satu kali retry setelah error 2006/2013.
     * Repository yang memegang PDO lama harus dibuat ulang oleh pemanggil.
     */
    public static function reconnect() {
        self::$instance = new Database();
        return self::$instance->conn;
    }

    /** Menentukan apakah Throwable berasal dari koneksi MySQL yang terputus. */
    public static function isLostConnection($exception): bool {
        $current = $exception;
        while ($current instanceof Throwable) {
            $message = strtolower((string) $current->getMessage());
            $code = (string) $current->getCode();
            $driverCode = $current instanceof PDOException
                && isset($current->errorInfo[1])
                    ? (int) $current->errorInfo[1]
                    : 0;
            if (
                $code === '2006'
                || $code === '2013'
                || $driverCode === 2006
                || $driverCode === 2013
                || strpos($message, 'server has gone away') !== false
                || strpos($message, 'lost connection') !== false
                || strpos($message, 'error while sending query packet') !== false
            ) {
                return true;
            }
            $current = $current->getPrevious();
        }
        return false;
    }
}
