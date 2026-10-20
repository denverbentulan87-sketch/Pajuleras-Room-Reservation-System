<?php
declare(strict_types=1);

/* Pop-up forms (inquiry, GCash payment) and the chat assistant.
   Each form also works as a normal page (inquire.php, gcash_pay.php) when JavaScript is off. */

/** Fields of the GCash payment report form. $p makes element ids unique per copy of the form. */
function gcash_pay_fields(string $p, array $old = []): string
{
    $v = fn(string $k, string $d = '') => e((string)($old[$k] ?? $d));
    $types = ['advance' => 'Advance payment', 'deposit' => 'Security deposit', 'rent' => 'Monthly rent', 'other' => 'Other'];
    ob_start(); ?>
<div class="hp" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
<div class="row-2">
  <label for="<?= $p ?>-res">Reservation number <span class="req">*</span>
    <input id="<?= $p ?>-res" type="text" name="reservation_no" value="<?= $v('reservation_no') ?>" maxlength="20" required placeholder="R2026-0001" autocapitalize="characters" autocomplete="off">
  </label>
  <label for="<?= $p ?>-tel">Your contact number <span class="req">*</span>
    <input id="<?= $p ?>-tel" type="tel" name="contact_no" value="<?= $v('contact_no') ?>" maxlength="30" required inputmode="tel" placeholder="09123456789" autocomplete="tel">
  </label>
</div>
<div class="row-2">
  <label for="<?= $p ?>-type">Payment for <span class="req">*</span>
    <select id="<?= $p ?>-type" name="payment_type" required>
      <option value="">Choose one</option>
      <?php foreach ($types as $k => $l): ?><option value="<?= $k ?>"<?= selected($old['payment_type'] ?? '', $k) ?>><?= e($l) ?></option><?php endforeach; ?>
    </select>
  </label>
  <label for="<?= $p ?>-amt">Amount sent (₱) <span class="req">*</span>
    <input id="<?= $p ?>-amt" type="text" name="amount" value="<?= $v('amount') ?>" inputmode="decimal" required placeholder="700.00" autocomplete="off">
  </label>
</div>
<div class="row-2">
  <label for="<?= $p ?>-ref">GCash reference number <span class="req">*</span>
    <input id="<?= $p ?>-ref" type="text" name="gcash_ref" value="<?= $v('gcash_ref') ?>" maxlength="40" required inputmode="numeric" placeholder="e.g. 1234 567 890123" autocomplete="off">
  </label>
  <label for="<?= $p ?>-date">Date paid <span class="req">*</span>
    <input id="<?= $p ?>-date" type="date" name="paid_date" value="<?= $v('paid_date', today()) ?>" max="<?= e(today()) ?>" required>
  </label>
</div>
<div class="row-2">
  <label for="<?= $p ?>-sender">Name on your GCash <span class="opt">(optional)</span>
    <input id="<?= $p ?>-sender" type="text" name="sender_name" value="<?= $v('sender_name') ?>" maxlength="120" autocomplete="name">
  </label>
  <label for="<?= $p ?>-proof">Screenshot of receipt <span class="opt">(optional, up to 3 MB)</span>
    <input id="<?= $p ?>-proof" class="file-input" type="file" name="proof" accept="image/jpeg,image/png,image/webp">
  </label>
</div>
<?php
    return (string)ob_get_clean();
}

/** The GCash "send to" card (number, name, copy button). */
function gcash_send_card(): string
{
    $no = setting('gcash_no');
    $name = setting('landlord_name');
    if ($no === '') {
        return '<div class="gc-card gc-card-empty"><strong>GCash number not set yet</strong><span>Please ask the landlord for the GCash number first.</span></div>';
    }
    return '<div class="gc-card"><div><span class="gc-label">Send your payment to</span><strong class="gc-no" data-copy-src>' . e($no) . '</strong>'
         . ($name !== '' ? '<span class="gc-name">' . e($name) . '</span>' : '') . '</div>'
         . '<button type="button" class="btn btn-sm gc-copy" data-copy="' . e($no) . '" aria-label="Copy GCash number">' . icon('copy') . '<span>Copy</span></button></div>';
}

function render_inquiry_modal(): void
{
    try {
        $rooms = rooms_with_status(true);
    } catch (Throwable $e) {
        $rooms = [];
    }
    ?>
<dialog id="inquiry-dialog" class="pbh-dialog" aria-labelledby="inq-title">
  <div class="pbh-shell">
    <aside class="pbh-art">
      <div class="pbh-art-inner">
        <span class="pbh-badge"><?= icon('message') ?></span>
        <p class="pbh-art-title">Say hello</p>
        <p class="pbh-art-sub">Ask about rooms, rates or requirements. The landlord replies personally.</p>
        <ul class="pbh-points">
          <li><?= icon('clock') ?><span>Usually answered within a day</span></li>
          <li><?= icon('shield') ?><span>No account or sign-up needed</span></li>
          <li><?= icon('key') ?><span>You get a code to read the reply</span></li>
        </ul>
      </div>
    </aside>
    <div class="pbh-panel">
      <button type="button" class="pbh-close" data-close-dialog aria-label="Close"><?= icon('x') ?></button>
      <div data-view="form">
        <p class="pbh-eyebrow"><?= icon('message') ?> New inquiry</p>
        <h2 id="inq-title">Send an inquiry</h2>
        <p class="muted pbh-lead">Fill in the form and we will get back to you.</p>
        <div class="pbh-errors" data-errors role="alert" hidden></div>
        <form class="form pbh-form" method="post" action="<?= e(url('inquire.php')) ?>" data-ajax-form="inquiry" novalidate>
          <?= csrf_field() ?>
          <div class="hp" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
          <div class="row-2">
            <label for="iq-name">Your full name <span class="req">*</span><input id="iq-name" type="text" name="name" maxlength="120" required autocomplete="name"></label>
            <label for="iq-tel">Contact number <span class="req">*</span><input id="iq-tel" type="tel" name="contact_no" maxlength="30" required inputmode="tel" placeholder="09123456789" autocomplete="tel"></label>
          </div>
          <div class="row-2">
            <label for="iq-mail">Email <span class="opt">(optional)</span><input id="iq-mail" type="email" name="email" maxlength="150" autocomplete="email"></label>
            <label for="iq-occ">I am a
              <select id="iq-occ" name="occupation"><option value="">Choose one</option><option value="student">Student</option><option value="employee">Employee</option><option value="other">Other</option></select>
            </label>
          </div>
          <div class="row-2">
            <label for="iq-room">Room you like
              <select id="iq-room" name="room_id"><option value="">Not sure yet</option>
                <?php foreach ($rooms as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e(room_title($r)) ?> (<?= e($r['status_label']) ?>)</option><?php endforeach; ?>
              </select>
            </label>
            <label for="iq-date">Move-in date <span class="opt">(optional)</span><input id="iq-date" type="date" name="move_in" min="<?= e(today()) ?>"></label>
          </div>
          <label for="iq-msg">Your message <span class="req">*</span>
            <textarea id="iq-msg" name="message" rows="4" maxlength="2000" required placeholder="For example: I am a first-year student and need a bed space starting June. What do I need to bring?"></textarea>
          </label>
          <button class="btn btn-primary btn-lg" type="submit" data-submit><span>Send inquiry</span></button>
        </form>
      </div>
      <div data-view="done" hidden>
        <div class="pbh-done">
          <span class="pbh-check"><?= icon('check') ?></span>
          <h2>Your inquiry was sent</h2>
          <p class="muted">The landlord will read your message and reply as soon as possible.</p>
          <div class="code-box" data-code-box><span>Your inquiry code</span><strong class="code" data-code></strong>
            <button type="button" class="btn btn-sm" data-copy-code><?= icon('copy') ?><span>Copy code</span></button></div>
          <p><strong>Write this code down.</strong> Use it with your contact number to read the reply.</p>
          <div class="pbh-done-actions"><a class="btn btn-primary" href="<?= e(url('track.php')) ?>">Check my inquiry</a><button type="button" class="btn" data-close-dialog>Close</button></div>
        </div>
      </div>
    </div>
  </div>
</dialog>
<?php
}

function render_track_modal(): void
{
    $inq = tracked_inquiry();   // someone who just looked up their inquiry sees it straight away
    ?>
<dialog id="track-dialog" class="pbh-dialog" aria-labelledby="trk-title">
  <div class="pbh-shell">
    <aside class="pbh-art">
      <div class="pbh-art-inner">
        <span class="pbh-badge"><?= icon('search') ?></span>
        <p class="pbh-art-title">Check my inquiry</p>
        <p class="pbh-art-sub">Read the landlord's reply and send a follow-up message.</p>
        <ul class="pbh-points">
          <li><?= icon('key') ?><span>Enter the code you were given</span></li>
          <li><?= icon('phone') ?><span>Use the same contact number or email</span></li>
          <li><?= icon('shield') ?><span>Your view closes by itself after 30 minutes</span></li>
        </ul>
      </div>
    </aside>
    <div class="pbh-panel">
      <button type="button" class="pbh-close" data-close-dialog aria-label="Close"><?= icon('x') ?></button>
      <div data-view="form"<?= $inq ? ' hidden' : '' ?>>
        <p class="pbh-eyebrow"><?= icon('search') ?> Reply status</p>
        <h2<?= $inq ? '' : ' id="trk-title"' ?>>Check my inquiry</h2>
        <p class="muted pbh-lead">Enter the code you received when you sent your inquiry, plus the contact number (or email) you used.</p>
        <div class="pbh-errors" data-errors role="alert" hidden></div>
        <form class="form pbh-form" method="post" action="<?= e(url('track.php')) ?>" data-ajax-form="track" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="lookup">
          <label for="tk-ref">Inquiry code <span class="req">*</span>
            <input id="tk-ref" type="text" name="ref" maxlength="30" required placeholder="K7M2-9QXA" autocomplete="off" autocapitalize="characters" spellcheck="false">
          </label>
          <label for="tk-contact">Contact number or email you used <span class="req">*</span>
            <input id="tk-contact" type="text" name="contact" maxlength="150" required autocomplete="off">
          </label>
          <button class="btn btn-primary btn-lg" type="submit" data-submit><span>Show my inquiry</span></button>
          <p class="muted small">Lost your code? Call the landlord<?= setting('phone') !== '' ? ' at ' . e(setting('phone')) : '' ?> or <a href="<?= e(url('inquire.php')) ?>">send a new inquiry</a>.</p>
        </form>
      </div>
      <div data-view="thread"<?= $inq ? '' : ' hidden' ?>>
        <div class="pbh-errors" data-errors role="alert" hidden></div>
        <div data-thread><?= $inq ? track_thread_html($inq, [], true) : '' ?></div>
      </div>
    </div>
  </div>
</dialog>
<?php
}

function render_gcash_modal(): void
{
    ?>
<dialog id="gcash-dialog" class="pbh-dialog pbh-gcash" aria-labelledby="gc-title">
  <div class="pbh-shell">
    <aside class="pbh-art">
      <div class="pbh-art-inner">
        <span class="pbh-badge"><?= icon('wallet') ?></span>
        <p class="pbh-art-title">Pay from anywhere</p>
        <p class="pbh-art-sub">Send by GCash, then report it here. The landlord confirms it and issues your official receipt.</p>
        <ol class="pbh-steps">
          <li><b>1</b><span>Send the amount to the landlord's GCash</span></li>
          <li><b>2</b><span>Keep the reference number from your receipt</span></li>
          <li><b>3</b><span>Fill in the form and send your report</span></li>
        </ol>
      </div>
    </aside>
    <div class="pbh-panel">
      <button type="button" class="pbh-close" data-close-dialog aria-label="Close"><?= icon('x') ?></button>
      <div data-view="form">
        <p class="pbh-eyebrow"><?= icon('wallet') ?> GCash</p>
        <h2 id="gc-title">Pay with GCash</h2>
        <?= gcash_send_card() ?>
        <div class="pbh-errors" data-errors role="alert" hidden></div>
        <form class="form pbh-form" method="post" enctype="multipart/form-data" action="<?= e(url('gcash_pay.php')) ?>" data-ajax-form="gcash" novalidate>
          <?= csrf_field() ?>
          <?= gcash_pay_fields('gp') ?>
          <button class="btn btn-primary btn-lg" type="submit" data-submit><span>Send payment report</span></button>
        </form>
      </div>
      <div data-view="done" hidden>
        <div class="pbh-done">
          <span class="pbh-check"><?= icon('check') ?></span>
          <h2>Payment report received</h2>
          <p class="muted">The landlord will check your GCash payment of <strong data-amount></strong> and confirm it. Your official receipt is issued once it is approved.</p>
          <div class="code-box"><span>Your report code</span><strong class="code" data-code></strong></div>
          <div class="pbh-done-actions"><button type="button" class="btn btn-primary" data-close-dialog>Done</button></div>
        </div>
      </div>
    </div>
  </div>
</dialog>
<?php
}

function render_chat_widget(): void
{
    $site = setting('site_name', 'Pajuleras Boarding House');
    ?>
<div class="chat" data-chat data-endpoint="<?= e(url('chatbot.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" data-site="<?= e($site) ?>">
  <button type="button" class="chat-launch" aria-expanded="false" aria-controls="chat-panel" data-chat-open>
    <span class="chat-launch-ic"><?= icon('bot') ?></span><span class="chat-launch-text">Ask us</span><i class="chat-dot" aria-hidden="true"></i>
  </button>
  <section id="chat-panel" class="chat-panel" role="dialog" aria-label="Chat assistant" hidden>
    <header class="chat-head">
      <span class="chat-avatar" aria-hidden="true"><?= icon('home') ?></span>
      <div class="chat-title"><strong>Boarding house assistant</strong><small><i class="live-dot" aria-hidden="true"></i> Answers instantly</small></div>
      <button type="button" class="chat-ibtn" data-chat-reset aria-label="Start over" title="Start over"><?= icon('refresh') ?></button>
      <button type="button" class="chat-ibtn" data-chat-close aria-label="Close chat"><?= icon('x') ?></button>
    </header>
    <div class="chat-log" data-chat-log role="log" aria-live="polite"></div>
    <div class="chat-chips" data-chat-chips></div>
    <form class="chat-form" data-chat-form autocomplete="off">
      <input type="text" name="q" maxlength="300" placeholder="Ask about rooms, rates, rules…" aria-label="Type your question" required>
      <button type="submit" class="chat-send" aria-label="Send"><?= icon('send') ?></button>
    </form>
    <p class="chat-note">Automated helper. For anything else, contact the landlord.</p>
  </section>
</div>
<?php
}
