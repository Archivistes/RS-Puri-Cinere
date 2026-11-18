<?php
declare(strict_types=1);

final class PostgresSession implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private ?PDO $connection = null;
    private ?string $locked = null;

    public function open(string $path, string $name): bool
    {
        $this->connection ??= openDatabase();
        return true;
    }

    public function close(): bool
    {
        if ($this->connection?->inTransaction()) {
            $this->connection->commit();
        }
        $this->locked = null;
        return true;
    }

    private function lock(string $id): string
    {
        $key = hash('sha256', $id);
        if ($this->locked === $key) {
            return $key;
        }
        $this->close();
        $this->connection->beginTransaction();
        $this->connection->exec("SET LOCAL lock_timeout = '5s'");
        $this->execute('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
        $this->locked = $key;
        return $key;
    }

    private function execute(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }

    public function validateId(string $id): bool
    {
        $key = $this->lock($id);
        $valid = $this->execute(
            'SELECT 1 FROM poli_runtime.web_session WHERE id_hash=? AND expires_at>clock_timestamp()', [$key]
        )->fetchColumn() !== false;
        if (!$valid) {
            $this->close();
        }
        return $valid;
    }

    public function read(string $id): string
    {
        $key = $this->lock($id);
        $data = $this->execute(
            'SELECT data FROM poli_runtime.web_session WHERE id_hash=? AND expires_at>clock_timestamp()', [$key]
        )->fetchColumn();
        return $data === false ? '' : (base64_decode($data, true) ?: '');
    }

    public function write(string $id, string $data): bool
    {
        $key = $this->lock($id);
        $this->execute("INSERT INTO poli_runtime.web_session (id_hash, data, expires_at)
            VALUES (?, ?, clock_timestamp() + interval '2 hours')
            ON CONFLICT (id_hash) DO UPDATE SET data=EXCLUDED.data, expires_at=EXCLUDED.expires_at",
            [$key, base64_encode($data)]
        );
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->execute('DELETE FROM poli_runtime.web_session WHERE id_hash=?', [$this->lock($id)]);
        return true;
    }

    public function gc(int $max_lifetime): int
    {
        // Skip sessions being accessed by concurrent requests.
        return $this->execute('DELETE FROM poli_runtime.web_session WHERE id_hash IN (
            SELECT id_hash FROM poli_runtime.web_session WHERE expires_at<=clock_timestamp()
            AND pg_try_advisory_xact_lock(hashtextextended(id_hash, 0)) LIMIT 500
        ) AND expires_at<=clock_timestamp()')->rowCount();
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }
}
