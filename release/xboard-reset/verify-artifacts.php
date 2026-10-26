<?php
/** File-only probe: no framework bootstrap, credentials, network or database access. */
try {
    $raw = isset($argv[1]) ? base64_decode($argv[1], true) : file_get_contents('/www/monexus-reset-release.json');
    $manifest = json_decode($raw ?: '', true, 64, JSON_THROW_ON_ERROR);
    if (($manifest['schemaVersion'] ?? null) !== 1 || !is_array($manifest['plugin']['files'] ?? null)) throw new RuntimeException();
    $corePaths = ['/www/app/Services/OrderService.php', '/www/app/Services/TrafficResetService.php'];
    if (array_keys($manifest['core']) !== $corePaths) throw new RuntimeException();
    $checks = [];
    foreach ($manifest['core'] as $path => $expected) {
        $checks[$path] = is_file($path) && !is_link($path) && hash_equals($expected, hash_file('sha256', $path));
    }
    $root = '/www/plugins/FakaBridge';
    $files = [];
    $allFiles = [];
    if (is_link($root) || !is_dir($root)) throw new RuntimeException();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        if ($file->isLink()) throw new RuntimeException();
        if ($file->isFile()) {
            $relative = substr($file->getPathname(), strlen($root));
            $allFiles[$relative] = hash_file('sha256', $file->getPathname());
            if (in_array($file->getExtension(), ['php', 'json'], true)) $files[$relative] = $allFiles[$relative];
        }
    }
    ksort($files);
    ksort($allFiles);
    $buildId = hash('sha256', json_encode($files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $checks['plugin_runtime_files'] = $files === $manifest['plugin']['files'];
    $checks['plugin_files'] = $allFiles === ($manifest['plugin']['allFiles'] ?? null);
    $checks['plugin_build_id'] = hash_equals($manifest['plugin']['buildId'], $buildId);
    $checks['embedded_manifest'] = is_file('/www/monexus-reset-release.json')
        && hash_equals(hash('sha256', $raw), hash_file('sha256', '/www/monexus-reset-release.json'));
    $ok = !in_array(false, $checks, true);
    echo json_encode(['ok' => $ok, 'coverage' => 'artifact_files_only', 'pluginBuildId' => $buildId, 'checks' => $checks], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit($ok ? 0 : 1);
} catch (Throwable $error) {
    // Never dump filesystem errors, configuration or arbitrary process output.
    echo "{\"ok\":false,\"coverage\":\"artifact_files_only\",\"error\":\"ARTIFACT_CHECK_FAILED\"}\n";
    exit(1);
}
