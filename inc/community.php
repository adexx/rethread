<?php
// Reviews, reports, blocking, follows, saved searches, price-drop alerts and chat safety checks.

// ---------- Catalog filters (shared by the catalog page and saved-search alerts) ----------

/** Reads catalog filters from a query array ($_GET or a saved search) into a normal form. */
function catalog_filters(array $in): array
{
    $list = function ($v) { return array_values(array_filter(array_map('strval', (array)($v ?? [])), function ($s) { return $s !== '' && mb_strlen($s) <= 60; })); };
    $cats = categories();
    $cat = (int)($in['cat'] ?? 0);
    return [
        'q' => mb_substr(trim((string)($in['q'] ?? '')), 0, 100),
        'cat' => isset($cats[$cat]) ? $cat : 0,
        'size' => $list($in['size'] ?? []),
        'brand' => $list($in['brand'] ?? []),
        'cond' => $list($in['cond'] ?? []),
        'color' => $list($in['color'] ?? []),
        'min' => ($in['min'] ?? '') !== '' && $in['min'] !== null ? (float)$in['min'] : null,
        'max' => ($in['max'] ?? '') !== '' && $in['max'] !== null ? (float)$in['max'] : null,
    ];
}

/** Builds the WHERE clause (aliases i = items, u = users) for visible listings matching the filters. */
function catalog_where(array $f): array
{
    $where = ["i.status = 'active'", 'u.vacation_mode = 0', 'u.banned = 0'];
    $params = [];
    if ($f['cat']) $where[] = 'i.category_id IN (' . implode(',', array_map('intval', category_descendants($f['cat']))) . ')';
    if ($f['q'] !== '') {
        $ft = fulltext_query($f['q']);
        if ($ft !== '') {
            // Uses the ft_search index instead of scanning every listing.
            $where[] = 'MATCH(i.title, i.brand, i.description) AGAINST (? IN BOOLEAN MODE)';
            $params[] = $ft;
        } else {
            // Only short words or stopwords (e.g. "XS"): fall back to a title/brand match.
            $where[] = '(i.title LIKE ? OR i.brand LIKE ?)';
            $like = '%' . like_escape($f['q']) . '%';
            array_push($params, $like, $like);
        }
    }
    foreach (['size' => 'i.size', 'brand' => 'i.brand', 'cond' => 'i.condition_label'] as $k => $col) {
        if (!$f[$k]) continue;
        $where[] = $col . ' IN (' . implode(',', array_fill(0, count($f[$k]), '?')) . ')';
        foreach ($f[$k] as $v) $params[] = $v;
    }
    if ($f['color']) {
        $or = [];
        foreach ($f['color'] as $c) { $or[] = 'FIND_IN_SET(?, i.colors)'; $params[] = $c; }
        $where[] = '(' . implode(' OR ', $or) . ')';
    }
    if ($f['min'] !== null) { $where[] = 'i.price >= ?'; $params[] = $f['min']; }
    if ($f['max'] !== null) { $where[] = 'i.price <= ?'; $params[] = $f['max']; }
    return [' WHERE ' . implode(' AND ', $where), $params];
}

/** Query string that reopens these filters in the catalog. */
function catalog_query(array $f): string
{
    $p = array_filter($f, function ($v) { return $v !== null && $v !== '' && $v !== [] && $v !== 0; });
    return http_build_query($p);
}

/** Short human label, e.g. "jeans · Levi's · M · under $40". */
function catalog_label(array $f): string
{
    $parts = [];
    if ($f['q'] !== '') $parts[] = '"' . $f['q'] . '"';
    if ($f['cat']) $parts[] = categories()[$f['cat']]['name'];
    foreach (['brand', 'size', 'cond', 'color'] as $k) if ($f[$k]) $parts[] = implode('/', $f[$k]);
    if ($f['min'] !== null && $f['max'] !== null) $parts[] = money($f['min']) . '–' . money($f['max']);
    elseif ($f['min'] !== null) $parts[] = 'from ' . money($f['min']);
    elseif ($f['max'] !== null) $parts[] = 'under ' . money($f['max']);
    return $parts ? mb_substr(implode(' · ', $parts), 0, 160) : 'All items';
}

// ---------- Saved searches ----------

const MAX_SAVED_SEARCHES = 20;

function saved_search_find(int $userId, array $f): ?array
{
    $json = json_encode($f);
    return q('SELECT * FROM saved_searches WHERE user_id = ? AND filters = ?', [$userId, $json])->fetch() ?: null;
}

function saved_search_add(int $userId, array $f): bool
{
    if (saved_search_find($userId, $f)) return true;
    if ((int)q('SELECT COUNT(*) FROM saved_searches WHERE user_id = ?', [$userId])->fetchColumn() >= MAX_SAVED_SEARCHES) return false;
    $newest = (int)q('SELECT COALESCE(MAX(id), 0) FROM items')->fetchColumn();   // only alert on listings added from now on
    q('INSERT INTO saved_searches (user_id, label, filters, last_item_id, checked_at, created_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
      [$userId, catalog_label($f), json_encode($f), $newest]);
    return true;
}

/**
 * Background job: tells members about new listings matching their saved searches.
 * Each search is checked at most every 15 minutes; one batch per cron run keeps the job short.
 */
function run_saved_search_alerts(int $batch = 300): int
{
    $alerts = 0;
    $rows = q('SELECT * FROM saved_searches WHERE checked_at IS NULL OR checked_at < NOW() - INTERVAL 15 MINUTE ORDER BY checked_at LIMIT ' . $batch)->fetchAll();
    foreach ($rows as $s) {
        $f = catalog_filters((array)json_decode($s['filters'], true));
        [$where, $params] = catalog_where($f);
        $where .= ' AND i.id > ? AND i.user_id <> ?';
        array_push($params, (int)$s['last_item_id'], (int)$s['user_id']);
        $ids = q('SELECT i.id FROM items i STRAIGHT_JOIN users u ON u.id = i.user_id' . $where . ' ORDER BY i.id DESC LIMIT 20', $params)->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) {
            $n = count($ids);
            notify((int)$s['user_id'], ($n >= 20 ? '20+' : $n) . ' new listing' . ($n === 1 ? '' : 's') . ' for your saved search ' . $s['label'] . '.', 'catalog.php?' . catalog_query($f) . '&sort=newest');
            q('UPDATE saved_searches SET last_item_id = ?, checked_at = NOW() WHERE id = ?', [max(array_map('intval', $ids)), $s['id']]);
            $alerts++;
        } else {
            q('UPDATE saved_searches SET checked_at = NOW() WHERE id = ?', [$s['id']]);
        }
    }
    return $alerts;
}

// ---------- Reviews ----------

const REVIEW_WINDOW_DAYS = 30;

/** Whether $userId may still review the other side of this order. */
function can_review(array $o, int $userId): bool
{
    if ($o['status'] !== 'completed' || empty($o['completed_at'])) return false;
    if (!in_array($userId, [(int)$o['buyer_id'], (int)$o['seller_id']], true)) return false;
    if (strtotime($o['completed_at']) < time() - REVIEW_WINDOW_DAYS * 86400) return false;
    return !q('SELECT 1 FROM reviews WHERE order_id = ? AND reviewer_id = ?', [$o['id'], $userId])->fetchColumn();
}

function review_submit(array $o, int $reviewerId, int $rating, string $body): void
{
    if (!can_review($o, $reviewerId)) throw new RuntimeException('This order can no longer be reviewed.');
    if ($rating < 1 || $rating > 5) throw new RuntimeException('Choose 1 to 5 stars.');
    $body = mb_substr(trim($body), 0, 1000);
    $isBuyer = $reviewerId === (int)$o['buyer_id'];
    $reviewee = (int)($isBuyer ? $o['seller_id'] : $o['buyer_id']);
    if (is_blocked_between($reviewerId, $reviewee)) throw new RuntimeException("You can't review a member you've blocked.");
    q('INSERT INTO reviews (order_id, reviewer_id, reviewee_id, role, rating, body, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
      [$o['id'], $reviewerId, $reviewee, $isBuyer ? 'buyer' : 'seller', $rating, $body]);
    refresh_rating($reviewee);
    $who = q('SELECT username FROM users WHERE id = ?', [$reviewerId])->fetchColumn();
    notify($reviewee, $who . ' left you a ' . $rating . '-star review.', 'member.php?id=' . $reviewee . '&tab=reviews', false);
}

function refresh_rating(int $userId): void
{
    q('UPDATE users SET rating_count = (SELECT COUNT(*) FROM reviews WHERE reviewee_id = ? AND hidden = 0),
                        rating_avg = (SELECT AVG(rating) FROM reviews WHERE reviewee_id = ? AND hidden = 0) WHERE id = ?', [$userId, $userId, $userId]);
}

/** Star rating, e.g. ★★★★☆ 4.3 (12). Shows "No reviews yet" when there are none. */
function stars(?float $avg, int $count, bool $withCount = true): string
{
    if (!$count || $avg === null) return '<span class="stars muted">No reviews yet</span>';
    $full = (int)round($avg);
    $s = str_repeat('★', $full) . str_repeat('☆', 5 - $full);
    return '<span class="stars" aria-label="Rated ' . number_format($avg, 1) . ' out of 5 from ' . $count . ' review' . ($count === 1 ? '' : 's') . '">'
         . '<span class="stars-ic" aria-hidden="true">' . $s . '</span>' . ($withCount ? ' <span class="muted">' . number_format($avg, 1) . ' (' . $count . ')</span>' : '') . '</span>';
}

// ---------- Blocking ----------

function is_blocked_between(int $a, int $b): bool
{
    return (bool)q('SELECT 1 FROM blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?) LIMIT 1', [$a, $b, $b, $a])->fetchColumn();
}

function has_blocked(int $blocker, int $blocked): bool
{
    return (bool)q('SELECT 1 FROM blocks WHERE blocker_id = ? AND blocked_id = ?', [$blocker, $blocked])->fetchColumn();
}

function block_user(int $blocker, int $blocked): void
{
    if ($blocker === $blocked) return;
    q('INSERT IGNORE INTO blocks (blocker_id, blocked_id, created_at) VALUES (?, ?, NOW())', [$blocker, $blocked]);
    // Blocking also ends any follow between the two members.
    foreach ([[$blocker, $blocked], [$blocked, $blocker]] as [$a, $b]) unfollow_user($a, $b);
}

function unblock_user(int $blocker, int $blocked): void
{
    q('DELETE FROM blocks WHERE blocker_id = ? AND blocked_id = ?', [$blocker, $blocked]);
}

// ---------- Follows ----------

function is_following(int $follower, int $followed): bool
{
    return (bool)q('SELECT 1 FROM follows WHERE follower_id = ? AND followed_id = ?', [$follower, $followed])->fetchColumn();
}

function follow_user(int $follower, int $followed): bool
{
    if ($follower === $followed || is_blocked_between($follower, $followed)) return false;
    $n = q('INSERT IGNORE INTO follows (follower_id, followed_id, created_at) VALUES (?, ?, NOW())', [$follower, $followed])->rowCount();
    if ($n) q('UPDATE users SET follower_count = follower_count + 1 WHERE id = ?', [$followed]);
    return true;
}

function unfollow_user(int $follower, int $followed): void
{
    $n = q('DELETE FROM follows WHERE follower_id = ? AND followed_id = ?', [$follower, $followed])->rowCount();
    if ($n) q('UPDATE users SET follower_count = GREATEST(follower_count - 1, 0) WHERE id = ?', [$followed]);
}

/** In-app notice (and push, when enabled) to everyone following a seller. One query, however many followers. */
function notify_followers(int $sellerId, string $body, string $link): void
{
    q('INSERT INTO notifications (user_id, body, link, created_at) SELECT follower_id, ?, ?, NOW() FROM follows WHERE followed_id = ?', [$body, $link, $sellerId]);
    if (push_enabled()) {
        q('INSERT INTO push_queue (user_id, title, body, url, created_at)
           SELECT DISTINCT f.follower_id, ?, ?, ?, NOW() FROM follows f JOIN push_subscriptions s ON s.user_id = f.follower_id WHERE f.followed_id = ?',
          [APP_NAME, mb_substr($body, 0, 300), $link, $sellerId]);
    }
}

/** Newest listings from members this user follows. */
function following_feed(int $userId, int $limit = 6): array
{
    $ids = q("SELECT i.id FROM follows f
              JOIN items i ON i.user_id = f.followed_id AND i.status = 'active'
              JOIN users u ON u.id = i.user_id AND u.vacation_mode = 0 AND u.banned = 0
              WHERE f.follower_id = ? ORDER BY i.sort_at DESC LIMIT " . (int)$limit, [$userId])->fetchAll(PDO::FETCH_COLUMN);
    return items_by_ids($ids);
}

// ---------- Price drops ----------

/** Tells members who favorited an item that its price went down. */
function notify_price_drop(array $item, float $oldPrice, float $newPrice): int
{
    if ($newPrice >= $oldPrice || $item['status'] !== 'active') return 0;
    $fans = q('SELECT user_id FROM favorites WHERE item_id = ? AND user_id <> ? LIMIT 1000', [$item['id'], $item['user_id']])->fetchAll(PDO::FETCH_COLUMN);
    foreach ($fans as $uid) {
        notify((int)$uid, 'Price drop: "' . $item['title'] . '" is now ' . money($newPrice) . ' (was ' . money($oldPrice) . ').', 'item.php?id=' . (int)$item['id']);
    }
    return count($fans);
}

// ---------- Reports ----------

const REPORT_REASONS = [
    'item' => ['Prohibited item', 'Counterfeit or replica', 'Misleading description or photos', 'Spam or duplicate', 'Offensive content', 'Other'],
    'user' => ['Scam or fraud', 'Asked to pay outside the app', 'Harassment or hate', 'Fake account', 'Other'],
    'message' => ['Scam or phishing', 'Asked to pay outside the app', 'Harassment or hate', 'Spam', 'Other'],
    'review' => ['Fake or unfair review', 'Offensive language', 'Shares personal information', 'Other'],
];

/** What a report points at: ['label', 'url' (relative to the site root), 'owner_id'] or null if it no longer exists. */
function report_target(string $type, int $id): ?array
{
    switch ($type) {
        case 'item':
            $r = q('SELECT id, title, user_id FROM items WHERE id = ?', [$id])->fetch();
            return $r ? ['label' => 'Listing "' . $r['title'] . '"', 'url' => 'item.php?id=' . $id, 'owner_id' => (int)$r['user_id']] : null;
        case 'user':
            $r = q('SELECT id, username FROM users WHERE id = ?', [$id])->fetch();
            return $r ? ['label' => 'Member ' . $r['username'], 'url' => 'member.php?id=' . $id, 'owner_id' => (int)$r['id']] : null;
        case 'message':
            $r = q('SELECT m.id, m.body, m.sender_id, m.conversation_id FROM messages m WHERE m.id = ?', [$id])->fetch();
            return $r ? ['label' => 'Message: "' . mb_strimwidth($r['body'], 0, 80, '…') . '"', 'url' => '', 'owner_id' => (int)$r['sender_id'], 'conversation_id' => (int)$r['conversation_id']] : null;
        case 'review':
            $r = q('SELECT id, body, rating, reviewer_id, reviewee_id FROM reviews WHERE id = ?', [$id])->fetch();
            return $r ? ['label' => $r['rating'] . '-star review: "' . mb_strimwidth($r['body'], 0, 80, '…') . '"', 'url' => 'member.php?id=' . $r['reviewee_id'] . '&tab=reviews', 'owner_id' => (int)$r['reviewer_id']] : null;
    }
    return null;
}

/** Files a report. Returns false when this member already reported the same thing. */
function report_create(int $reporterId, string $type, int $id, string $reason, string $details): bool
{
    if (!isset(REPORT_REASONS[$type]) || !in_array($reason, REPORT_REASONS[$type], true)) throw new RuntimeException('Choose a reason.');
    $target = report_target($type, $id);
    if (!$target) throw new RuntimeException('That no longer exists.');
    if ($target['owner_id'] === $reporterId && $type !== 'review') throw new RuntimeException("You can't report yourself.");
    $firstOpen = !q("SELECT 1 FROM reports WHERE target_type = ? AND target_id = ? AND status = 'open' LIMIT 1", [$type, $id])->fetchColumn();
    $n = q('INSERT IGNORE INTO reports (reporter_id, target_type, target_id, reason, details, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
           [$reporterId, $type, $id, $reason, mb_substr(trim($details), 0, 1000)])->rowCount();
    if ($n && $firstOpen) {
        // One heads-up per reported thing, in-app only, so a pile-on doesn't flood admins' email.
        foreach (q("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN) as $a) {
            notify((int)$a, 'New report: ' . $target['label'] . ' (' . $reason . ').', 'admin/reports.php', false);
        }
    }
    return $n > 0;
}

// ---------- Chat safety ----------

/**
 * Flags messages that try to move the conversation or the payment off the platform, the usual
 * first step of a scam. Returns the reasons found (empty when the message looks fine).
 */
function message_risk(string $body): array
{
    $reasons = [];
    $text = mb_strtolower($body);
    if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $body)) $reasons[] = 'an email address';
    if (preg_match('/(?:\+?\d[\s().\-]*){9,}/', $body)) $reasons[] = 'a phone number';
    if (preg_match('~(https?://|www\.)\S+|\b[a-z0-9-]+\.(?:com|net|org|io|me|ly|co|app|link|shop|xyz)\b~i', $body)) $reasons[] = 'a link';
    if (preg_match('/\b(pay ?pal|venmo|zelle|cash ?app|western union|moneygram|wire transfer|bank transfer|gift ?cards?|crypto|bitcoin|btc|usdt|friends (?:and|&) family)\b/i', $text)) $reasons[] = 'paying outside ' . APP_NAME;
    if (preg_match('/\b(whats ?app|telegram|signal|kik|snapchat|text me|call me|my number|dm me on)\b/i', $text)) $reasons[] = 'chatting outside ' . APP_NAME;
    return $reasons;
}

/** Adds a chat message and keeps the conversation's "latest message" pointer current (used by the inbox). */
function add_message(int $conversationId, int $senderId, string $body, ?float $offer = null, ?string $offerStatus = null, int $flagged = 0): int
{
    q('INSERT INTO messages (conversation_id, sender_id, body, offer_amount, offer_status, flagged, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
      [$conversationId, $senderId, $body, $offer, $offerStatus, $flagged]);
    $mid = (int)db()->lastInsertId();
    q('UPDATE conversations SET last_message_id = ?, updated_at = NOW() WHERE id = ?', [$mid, $conversationId]);
    return $mid;
}
