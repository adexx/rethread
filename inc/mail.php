<?php
// Email: every message goes into the `emails` outbox table first.
//   MAIL_DRIVER=log  -> stored only (read them in Admin > Emails). Good for local dev.
//   MAIL_DRIVER=smtp -> cron.php sends queued mail via SMTP. Transactional emails
//                       (verification, password reset) are also sent immediately.

/** Build a simple, mobile-friendly HTML email. $cta = [label, url]. */
function mail_layout(string $greeting, array $paragraphs, ?array $cta = null, string $footnote = ''): string
{
    $p = '';
    foreach ($paragraphs as $para) $p .= '<p style="margin:0 0 14px;line-height:1.5">' . e($para) . '</p>';
    $btn = $cta ? '<p style="margin:22px 0"><a href="' . e($cta[1]) . '" style="background:#e08a3c;color:#1b1309;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:600;display:inline-block">' . e($cta[0]) . '</a></p>'
                . '<p style="font-size:12px;color:#777;margin:0 0 14px">Or paste this link into your browser:<br>' . e($cta[1]) . '</p>' : '';
    $foot = $footnote ? '<p style="font-size:12px;color:#777">' . e($footnote) . '</p>' : '';
    return '<!doctype html><html><body style="margin:0;background:#f4f2ef;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1d1f22">'
         . '<div style="max-width:520px;margin:0 auto;padding:24px">'
         . '<div style="font-size:22px;font-weight:700;margin-bottom:18px">' . e(APP_NAME) . '</div>'
         . '<div style="background:#fff;border-radius:12px;padding:24px">'
         . '<p style="margin:0 0 14px;font-weight:600">' . e($greeting) . '</p>' . $p . $btn . $foot
         . '</div><p style="font-size:12px;color:#888;margin-top:18px">' . e(COMPANY_NAME . ' · ' . COMPANY_ADDRESS)
         . '<br>You can turn off notification emails in Settings. Questions? ' . e(SUPPORT_EMAIL) . '</p></div></body></html>';
}

function queue_email(string $to, string $subject, string $html, ?int $userId = null, bool $sendNow = false): int
{
    $text = trim(html_entity_decode(strip_tags(preg_replace(['#<br\s*/?>#i', '#</p>#i'], ["\n", "\n\n"], $html)), ENT_QUOTES, 'UTF-8'));
    q("INSERT INTO emails (user_id, to_email, subject, body_html, body_text, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())",
      [$userId, $to, $subject, $html, $text, MAIL_DRIVER === 'smtp' ? 'queued' : 'logged']);
    $id = (int)db()->lastInsertId();
    if ($sendNow && MAIL_DRIVER === 'smtp') send_email_row($id);
    return $id;
}

/** Sends queued emails (called from cron.php). Returns [sent, failed]. */
function send_queued_emails(int $limit = 50): array
{
    if (MAIL_DRIVER !== 'smtp') return [0, 0];
    $ids = q("SELECT id FROM emails WHERE status = 'queued' AND attempts < 5 ORDER BY id LIMIT $limit")->fetchAll(PDO::FETCH_COLUMN);
    $ok = $bad = 0;
    foreach ($ids as $id) send_email_row((int)$id) ? $ok++ : $bad++;
    return [$ok, $bad];
}

function send_email_row(int $id): bool
{
    $m = q('SELECT * FROM emails WHERE id = ?', [$id])->fetch();
    if (!$m || $m['status'] === 'sent') return true;
    try {
        smtp_send($m['to_email'], $m['subject'], $m['body_html'], $m['body_text']);
        q("UPDATE emails SET status = 'sent', sent_at = NOW(), attempts = attempts + 1, error = NULL WHERE id = ?", [$id]);
        return true;
    } catch (Throwable $e) {
        error_log('Email ' . $id . ' failed: ' . $e->getMessage());
        q("UPDATE emails SET attempts = attempts + 1, error = ?, status = IF(attempts + 1 >= 5, 'failed', 'queued') WHERE id = ?", [mb_substr($e->getMessage(), 0, 250), $id]);
        return false;
    }
}

/** Minimal SMTP client: STARTTLS or implicit TLS, AUTH LOGIN, multipart/alternative. */
function smtp_send(string $to, string $subject, string $html, string $text): void
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Bad recipient');
    $host = (SMTP_SECURE === 'ssl' ? 'ssl://' : 'tcp://') . SMTP_HOST . ':' . SMTP_PORT;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client($host, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
    stream_set_timeout($fp, 20);
    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) { $data .= $line; if (isset($line[3]) && $line[3] === ' ') break; }
        return $data;
    };
    $cmd = function (string $c, array $expect) use ($fp, $read) {
        if ($c !== '') fwrite($fp, $c . "\r\n");
        $r = $read();
        if (!in_array((int)substr($r, 0, 3), $expect, true)) throw new RuntimeException('SMTP error after "' . explode(' ', $c)[0] . '": ' . trim($r));
        return $r;
    };
    $local = parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost';
    $cmd('', [220]);
    $cmd('EHLO ' . $local, [250]);
    if (SMTP_SECURE === 'tls') {
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('STARTTLS failed');
        $cmd('EHLO ' . $local, [250]);
    }
    if (SMTP_USER !== '') {
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode(SMTP_USER), [334]);
        $cmd(base64_encode(SMTP_PASS), [235]);
    }
    $cmd('MAIL FROM:<' . MAIL_FROM . '>', [250]);
    $cmd('RCPT TO:<' . $to . '>', [250, 251]);
    $cmd('DATA', [354]);
    $b = 'b' . bin2hex(random_bytes(8));
    $enc = function (string $s) { return '=?UTF-8?B?' . base64_encode($s) . '?='; };
    $headers = [
        'Date: ' . date('r'),
        'From: ' . $enc(MAIL_FROM_NAME) . ' <' . MAIL_FROM . '>',
        'To: <' . $to . '>',
        'Subject: ' . $enc($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $local . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $b . '"',
    ];
    $body = implode("\r\n", $headers) . "\r\n\r\n"
          . "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
          . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
          . "--$b--\r\n";
    $body = preg_replace('/^\./m', '..', $body);   // dot-stuffing
    fwrite($fp, $body . "\r\n.\r\n");
    $cmd('', [250]);
    $cmd('QUIT', [221]);
    fclose($fp);
}
