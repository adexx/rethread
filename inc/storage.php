<?php
// Photo storage. Paths in the database always look like "uploads/<random>.webp"; this file decides
// where those files live (this server's uploads/ folder, or an S3-compatible bucket) and which URL
// serves them (this site, the bucket, or a CDN in front of either).

function storage_is_s3(): bool
{
    return STORAGE_DRIVER === 's3';
}

/** Stores a finished local file under $key ("uploads/abc.webp") and removes the temp file. */
function storage_put(string $file, string $key, string $contentType): void
{
    if (storage_is_s3()) {
        $code = s3_request('PUT', $key, (string)file_get_contents($file), [
            'content-type' => $contentType,
            'cache-control' => 'public, max-age=31536000, immutable',   // names are random and never reused
        ]);
        @unlink($file);
        if ($code < 200 || $code >= 300) throw new RuntimeException('Photo upload to storage failed (HTTP ' . $code . ').');
        return;
    }
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    if (!rename($file, UPLOAD_DIR . '/' . basename($key))) throw new RuntimeException('Could not save the photo.');
}

function storage_delete(string $key): void
{
    if (strpos($key, 'uploads/') !== 0 || strpos($key, '..') !== false) return;   // seed/demo paths aren't ours to delete
    if (storage_is_s3()) {
        try { s3_request('DELETE', $key); } catch (Throwable $e) { error_log('storage delete ' . $key . ': ' . $e->getMessage()); }
        return;
    }
    $full = UPLOAD_DIR . '/' . basename($key);
    if (is_file($full)) unlink($full);
}

/** Public URL for a stored photo path. Paths that aren't uploads (e.g. placeholder.php) pass through. */
function media_url(string $path): string
{
    if (strpos($path, 'uploads/') !== 0) return $path;
    if (MEDIA_URL !== '') return MEDIA_URL . '/' . $path;
    if (storage_is_s3()) return s3_object_url($path);
    return $path;
}

// ---------- S3 (Signature Version 4) ----------

function s3_object_url(string $key): string
{
    $path = implode('/', array_map('rawurlencode', explode('/', $key)));
    if (S3_ENDPOINT !== '') return rtrim(S3_ENDPOINT, '/') . '/' . S3_BUCKET . '/' . $path;   // path-style (R2, Spaces, MinIO…)
    return 'https://' . S3_BUCKET . '.s3.' . S3_REGION . '.amazonaws.com/' . $path;
}

/** Signed request to the bucket. Returns the HTTP status. */
function s3_request(string $method, string $key, string $body = '', array $headers = []): int
{
    if (S3_BUCKET === '' || S3_KEY === '' || S3_SECRET === '') throw new RuntimeException('S3 storage is not configured.');
    $url = s3_object_url($key);
    $headers = array_change_key_case($headers, CASE_LOWER);
    $headers['host'] = (string)parse_url($url, PHP_URL_HOST) . (parse_url($url, PHP_URL_PORT) ? ':' . parse_url($url, PHP_URL_PORT) : '');
    $headers['x-amz-content-sha256'] = hash('sha256', $body);
    $headers['x-amz-date'] = gmdate('Ymd\THis\Z');
    $headers['authorization'] = sigv4_authorization($method, (string)parse_url($url, PHP_URL_PATH), '', $headers, $headers['x-amz-content-sha256'],
        S3_REGION, 's3', S3_KEY, S3_SECRET, $headers['x-amz-date']);
    $lines = [];
    foreach ($headers as $k => $v) if ($k !== 'host') $lines[] = $k . ': ' . $v;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $lines,
    ]);
    if ($method === 'PUT') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $res = curl_exec($ch);
    if ($res === false) { $err = curl_error($ch); curl_close($ch); throw new RuntimeException('Storage request failed: ' . $err); }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 300 && $method !== 'DELETE') error_log('S3 ' . $method . ' ' . $key . ' -> ' . $code . ': ' . mb_substr((string)$res, 0, 300));
    return $code;
}

/**
 * AWS Signature Version 4 "Authorization" header value.
 * $headers: lowercase name => value, must include host and x-amz-date. $path: already URI-encoded.
 */
function sigv4_authorization(string $method, string $path, string $query, array $headers, string $payloadHash,
                             string $region, string $service, string $accessKey, string $secret, string $amzDate): string
{
    ksort($headers);
    $canonHeaders = '';
    foreach ($headers as $k => $v) $canonHeaders .= $k . ':' . trim(preg_replace('/\s+/', ' ', (string)$v)) . "\n";
    $signed = implode(';', array_keys($headers));
    $canonical = implode("\n", [$method, $path === '' ? '/' : $path, $query, $canonHeaders, $signed, $payloadHash]);
    $date = substr($amzDate, 0, 8);
    $scope = "$date/$region/$service/aws4_request";
    $toSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonical);
    $k = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
    $k = hash_hmac('sha256', $region, $k, true);
    $k = hash_hmac('sha256', $service, $k, true);
    $k = hash_hmac('sha256', 'aws4_request', $k, true);
    return "AWS4-HMAC-SHA256 Credential=$accessKey/$scope, SignedHeaders=$signed, Signature=" . hash_hmac('sha256', $toSign, $k);
}
