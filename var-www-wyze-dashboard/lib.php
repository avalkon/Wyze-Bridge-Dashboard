<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

function recording_root(): string {
    return rtrim((string)cfg()['recordings_dir'], '/');
}

function list_recordings(): array {
    $root = recording_root();
    if (!is_dir($root)) return [];

    $rows = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
    RecursiveIteratorIterator::CATCH_GET_CHILD
);

    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'mp4') continue;
        $relative = substr($f->getPathname(), strlen($root) + 1);
        $parts = explode(DIRECTORY_SEPARATOR, $relative);
        $camera = count($parts) > 1 ? $parts[0] : 'camera';

        $rows[] = [
            'path' => str_replace(DIRECTORY_SEPARATOR, '/', $relative),
            'camera' => $camera,
            'name' => $f->getFilename(),
            'mtime' => $f->getMTime(),
            'size' => $f->getSize(),
        ];
    }

    usort($rows, fn($a,$b) => $b['mtime'] <=> $a['mtime']);
    return $rows;
}

function read_events(): array {
    $file = (string)cfg()['events_file'];
    if (!is_file($file)) return [];

    $data = json_decode((string)file_get_contents($file), true);
    if (!is_array($data)) return [];

    $events = $data['events'] ?? $data;
    if (!is_array($events)) return [];

    $out = [];
    foreach ($events as $event) {
        if (is_array($event) && isset($event['ts'], $event['camera'])) {
            $out[] = $event;
        }
    }
    usort($out, fn($a,$b) => ((int)$b['ts']) <=> ((int)$a['ts']));
    return $out;
}

function helper_request(string $method, string $path, ?array $payload = null): array {
    $c = cfg();
    $url = rtrim((string)$c['helper_url'], '/') . $path;
    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . (string)$c['helper_token'],
        'Content-Type: application/json',
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 8,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        throw new RuntimeException($error ?: "Helper returned HTTP {$status}: {$body}");
    }
    $decoded = json_decode((string)$body, true);
    return is_array($decoded) ? $decoded : [];
}

function protected_files(): array {
    try {
        $data = helper_request('GET', '/protected');
        return array_fill_keys($data['files'] ?? [], true);
    } catch (Throwable $e) {
        return [];
    }
}

function recording_for_event(array $event, array $recordings, int $window = 600): ?array {
    $ts = (int)($event['ts'] ?? 0);
    $camera = (string)($event['camera'] ?? '');
    $best = null; $bestDelta = PHP_INT_MAX;
    foreach ($recordings as $r) {
        if ($r['camera'] !== $camera) continue;
        $delta = abs((int)$r['mtime'] - $ts);
        if ($delta <= $window && $delta < $bestDelta) {
            $best = $r; $bestDelta = $delta;
        }
    }
    return $best;
}

function human_bytes(int $bytes): string {
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    $v = (float)$bytes;
    while ($v >= 1024 && $i < count($units)-1) {
        $v /= 1024; $i++;
    }
    return sprintf($i === 0 ? '%.0f %s' : '%.1f %s', $v, $units[$i]);
}
