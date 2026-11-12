<?php
// Shared wrapper for Terms / Privacy / Prohibited items.
// These are starting drafts. They are NOT legal advice; have a lawyer adapt them to your
// business and state, then set LEGAL_REVIEWED=true in config to remove the draft notice.
function legal_start(string $title, string $updated): void
{
    page_start($title, ['back' => true, 'tab' => null]);
    echo '<article class="pad legal" style="line-height:1.6">';
    if (!LEGAL_REVIEWED) {
        echo '<div class="flash flash-err" style="margin:0 0 16px">Draft: this document has not been reviewed by a lawyer yet.</div>';
    }
    echo '<p class="muted small">Last updated ' . e($updated) . ' · ' . e(COMPANY_NAME) . '</p>';
}

function legal_end(): void
{
    echo '<p class="muted small">Questions? Contact ' . e(COMPANY_NAME) . ', ' . e(COMPANY_ADDRESS) . ', ' . e(SUPPORT_EMAIL) . '.</p></article>';
    page_end();
}
