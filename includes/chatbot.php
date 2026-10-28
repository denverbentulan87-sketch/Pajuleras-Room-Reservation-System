<?php
declare(strict_types=1);

/**
 * The website chat assistant.
 *
 * It answers from the same information the public website already shows
 * (Settings, rooms, rates, requirements, rules, payment details, landlord
 * contact), read live from the database, so answers stay current when the
 * landlord edits anything. It runs on this server only: no outside service,
 * no API key. It never reveals passwords, tenant records or financial
 * reports.
 */

/** Everything the assistant is allowed to talk about. */
function chatbot_knowledge(): array
{
    try {
        $rooms = rooms_with_status(true);
    } catch (Throwable $e) {
        $rooms = [];
    }
    return [
        'site'     => setting('site_name', 'Pajuleras Boarding House'),
        'tagline'  => setting('tagline'),
        'landlord' => setting('landlord_name'),
        'address'  => setting('address'),
        'phone'    => setting('phone'),
        'email'    => setting('email'),
        'facebook' => setting('facebook'),
        'gcash'    => setting('gcash_no'),
        'hours'    => setting('contact_hours'),
        'about'    => setting('about_text'),
        'advance'  => (int)setting('advance_months', '1'),
        'life'     => lifecycle_settings(),
        'requirements' => lines(setting('requirements')),
        'payments' => lines(setting('payment_schedule')),
        'rules'    => lines(setting('house_rules')),
        'rooms'    => $rooms,
    ];
}

function chat_has(string $text, array $words): int
{
    $n = 0;
    foreach ($words as $w) {
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '(?![\p{L}\p{N}])/u', $text)) {
            $n += str_contains($w, ' ') ? 2 : 1;
        }
    }
    return $n;
}

function chat_rooms_line(array $r): string
{
    $open = (int)$r['open_beds'];
    $state = $r['status_key'] === 'available'
        ? $open . ' ' . ($open === 1 ? 'bed' : 'beds') . ' open'
        : strtolower($r['status_label']);
    return '• **' . room_title($r) . '**: ' . rate_label($r) . ', ' . $state . ' (' . strtolower(gender_label($r['gender_policy'])) . ')';
}

function chat_act_inquiry(string $label = 'Send an inquiry', int $room = 0): array
{
    return ['type' => 'inquiry', 'label' => $label, 'room' => $room];
}

function chat_act_link(string $label, string $path): array
{
    return ['type' => 'link', 'label' => $label, 'href' => url($path)];
}

function chat_contact_text(array $kb): string
{
    $o = [];
    if ($kb['landlord'] !== '') $o[] = '• Landlord: **' . $kb['landlord'] . '**';
    if ($kb['phone'] !== '')    $o[] = '• Call or text: **' . $kb['phone'] . '**';
    if ($kb['email'] !== '')    $o[] = '• Email: ' . $kb['email'];
    if ($kb['facebook'] !== '') $o[] = '• Facebook: ' . $kb['facebook'];
    if ($kb['address'] !== '')  $o[] = '• Address: ' . $kb['address'];
    if ($kb['hours'] !== '')    $o[] = '• Replies: ' . $kb['hours'];
    return implode("\n", $o);
}


/**
 * Messages that are not questions: thanks, "ok", praise for the answers, "that's all".
 * @return string|null 'thanks' | 'praise' | 'ack' | 'done' | null
 */
function chat_social(string $q, string $raw): ?string
{
    if ($q === '') {
        // emoji only: 👍 🙏 😊 etc.
        return preg_match('/[\x{1F44D}\x{1F44C}\x{1F64F}\x{1F60A}\x{1F642}\x{1F600}\x{1F601}\x{1F604}\x{1F60D}\x{2764}\x{263A}\x{1F44F}\x{1F4AF}]/u', $raw) ? 'thanks' : null;
    }
    $words = preg_split('/\s+/', $q) ?: [];
    if (chat_has($q, ['that is all', 'thats all', 'that s all', 'nothing else', 'nothing more', 'no more questions', 'no more', 'all good', 'i m good', 'im good', 'i am good', 'wala na', 'ayaw na', 'dili na', 'no need', 'maybe later', 'not now', 'no thanks'])) {
        return 'done';
    }
    if (preg_match('/(?<![\p{L}\p{N}])(thank(?:s|you|u|ee)?|thanku|thankyou|thx|thnx|thanx|tnx|tanks|tysm|ty|salamat\p{L}*|salamuch|maraming salamat|daghang salamat|arigato|gracias|appreciate\p{L}*|grateful|many thanks)(?![\p{L}\p{N}])/u', $q)) {
        return 'thanks';
    }
    if (chat_has($q, ['good bot', 'great bot', 'good job', 'great job', 'well done', 'nice answer', 'great answer', 'good answer', 'nice one', 'very helpful', 'so helpful', 'helpful', 'that helps', 'that helped', 'you helped', 'you are helpful', 'you re helpful', 'youre helpful', 'love it', 'amazing', 'awesome', 'excellent', 'impressive', 'smart bot', 'ang galing', 'galing', 'maayo kaayo', 'nindot'])) {
        return 'praise';
    }
    $ack = ['ok', 'okay', 'okey', 'oki', 'okie', 'oks', 'okk', 'okkk', 'k', 'kk', 'alright', 'aight', 'sige', 'sigeh', 'sure', 'noted', 'got', 'it', 'gotcha', 'i', 'see', 'understood', 'understand', 'fine', 'cool', 'nice', 'great', 'good', 'perfect', 'yes', 'yep', 'yeah', 'yup', 'oo', 'opo', 'uu', 'ah', 'aha', 'oh', 'hmm', 'hm', 'right', 'ayos', 'sounds', 'makes', 'sense', 'wow', 'then', 'po', 'ha', 'ra', 'lang', 'na', 'ay', 'ahh', 'ohh', 'ooh', 'yay', 'ok', 'hehe', 'haha', 'lol', 'so', 'be', 'it', 'that', 's', 'thats', 'dili', 'sge'];
    if (count($words) <= 6) {
        $only = true;
        foreach ($words as $w) {
            if (!in_array($w, $ack, true) && !preg_match('/^o+k+(?:a+y+|i+e*|e+y*|s+)?$/', $w) && !preg_match('/^do+k+(?:ie|ey|i)$/', $w) && !preg_match('/^(?:ha|he){2,}$/', $w)) {
                $only = false;
                break;
            }
        }
        if ($only) {
            return 'ack';
        }
    }
    return null;
}

/** A varied, friendly reply to a social message. */
function chat_social_reply(string $kind, string $q, array $kb, bool $local): array
{
    $pick = fn(array $options) => $options[crc32($q) % count($options)];
    $chips = ['Available rooms', 'Rates', 'How to pay', 'Contact the landlord'];
    switch ($kind) {
        case 'thanks':
            $opts = [
                "You're welcome! 😊 Happy to help. Is there anything else you'd like to know?",
                "Glad I could help! Ask me anytime if you have more questions. 🏠",
                "My pleasure! Let me know if you need anything else, like rates, house rules or how to pay.",
                "Anytime! If you're ready to reserve a bed, I can open the inquiry form for you.",
            ];
            if ($local) {
                $opts = ["Walay sapayan! 😊 Kung naay lain nga pangutana, ingna lang ko.", "Walang anuman! 😊 Narito lang ako kung may iba ka pang tanong.", "You're welcome! 😊 Salamat sad. Naa pa kay pangutana?"];
            }
            return ['text' => $pick($opts), 'actions' => [chat_act_inquiry('Send an inquiry')], 'chips' => $chips];
        case 'praise':
            return ['text' => $pick([
                "Thank you, that's kind of you! 😊 I'm glad the answers were useful. Anything else I can help with?",
                "Thanks, I appreciate that! 🏠 Want to see the available rooms or send an inquiry?",
                "That means a lot, thank you! Ask me anything else about the boarding house.",
            ]), 'actions' => [], 'chips' => $chips];
        case 'done':
            return ['text' => $pick([
                "Alright! Thank you for visiting " . $kb['site'] . ". If you decide to reserve a bed, you can send an inquiry anytime. 🏠",
                "No problem! I'm here whenever you need me. Have a great day! 😊",
            ]), 'actions' => [chat_act_inquiry('Send an inquiry')], 'chips' => []];
        default: // ack
            return ['text' => $pick([
                "Great! 👍 Let me know if you have any other questions.",
                "Sure! I'm here if you need anything else.",
                "Alright! Is there anything else I can help you with?",
                "Noted! You can ask me about rooms, rates, house rules or payments anytime.",
            ]), 'actions' => [], 'chips' => $chips];
    }
}

/** @return array{text:string, actions:array, chips:array} */
function chatbot_reply(string $raw, array $kb): array
{
    $q = mb_strtolower(trim($raw));
    $q = preg_replace('/[^\p{L}\p{N}\s#\-]/u', ' ', $q) ?? '';
    $q = trim(preg_replace('/\s+/', ' ', $q) ?? '');
    $social = chat_social($q, $raw);
    if ($social !== null && $social !== 'thanks') {
        return chat_social_reply($social, $q, $kb, false);
    }
    if ($social === 'thanks') {
        $answer = str_contains($raw, '?') || chat_has($q, ['how', 'what', 'where', 'when', 'who', 'which', 'can', 'could', 'is', 'are', 'do', 'does', 'pila', 'unsa', 'asa', 'naa', 'ngano', 'unsaon', 'ano', 'saan', 'magkano', 'pwede']) > 0;
        if (!$answer) {
            return chat_social_reply('thanks', $q, $kb, (bool)preg_match('/salamat|walay|daghang|maraming/u', $q));
        }
        // thanks AND a new question: answer the question, politely
        $r = chatbot_answer($raw, $kb, true);
        if (!$r['social_only']) {
            $r['text'] = "You're welcome! 😊\n\n" . $r['text'];
            unset($r['social_only']);
            return $r;
        }
        return chat_social_reply('thanks', $q, $kb, (bool)preg_match('/salamat|walay|daghang|maraming/u', $q));
    }
    $r = chatbot_answer($raw, $kb, false);
    unset($r['social_only']);
    return $r;
}

/** The main question answering (rooms, rates, rules...). With $probe, flags answers that were only a fallback. */
function chatbot_answer(string $raw, array $kb, bool $probe): array
{
    $q = mb_strtolower(trim($raw));
    $q = preg_replace('/[^\p{L}\p{N}\s#\-]/u', ' ', $q) ?? '';
    $q = trim(preg_replace('/\s+/', ' ', $q) ?? '');
    $rooms = $kb['rooms'];
    $out = fn(string $t, array $a = [], array $c = []) => ['text' => $t, 'actions' => $a, 'chips' => $c, 'social_only' => false];
    $main = ['Available rooms', 'Rates', 'Requirements', 'How to pay', 'House rules', 'Location', 'Contact the landlord'];

    if ($q === '') {
        return $out('Please type your question, or tap one of the buttons below.', [], $main);
    }

    /* ---- privacy: never share private records ---- */
    if (chat_has($q, ['password', 'passwords', 'credential', 'credentials', 'username', 'login details', 'account details', 'database', 'tenant list', 'list of tenants', 'who is staying', 'who lives', 'tenant information', 'tenants information', 'other tenants', 'income', 'earnings', 'financial report', 'sales report', 'hack'])) {
        return $out("I can't share private information such as passwords, tenant records or financial reports. Those stay with the landlord.\n\nI can tell you about rooms, rates, requirements, house rules, payments and how to reach the landlord.", [chat_act_inquiry('Message the landlord')], $main);
    }

    /* ---- a specific room? ---- */
    foreach ($rooms as $r) {
        $no = mb_strtolower((string)$r['room_no']);
        $named = !empty($r['room_name']) && mb_strlen((string)$r['room_name']) > 2 && str_contains($q, mb_strtolower((string)$r['room_name']));
        if ($named || preg_match('/(?<![\p{L}\p{N}])(?:room|rm|kwarto|kuwarto)\s*#?\s*' . preg_quote($no, '/') . '(?![\p{L}\p{N}])/u', $q)) {
            $t = '**' . room_title($r) . "**\n• Rent: " . rate_label($r) . "\n• Beds: " . (int)$r['capacity'] . ' total, ' . (int)$r['open_beds'] . " open\n• For: " . gender_label($r['gender_policy']) . "\n• Status: " . $r['status_label'];
            if ((float)$r['deposit_amount'] > 0) $t .= "\n• Security deposit: " . money_short($r['deposit_amount']);
            if (!empty($r['amenities'])) $t .= "\n• Includes: " . $r['amenities'];
            if (!empty($r['description'])) $t .= "\n\n" . mb_substr((string)$r['description'], 0, 280);
            return $out($t, [chat_act_link('View room', 'room.php?id=' . (int)$r['id']), chat_act_inquiry('Inquire about this room', (int)$r['id'])], ['Available rooms', 'Requirements', 'How to pay']);
        }
    }

    $is = fn(array $w) => chat_has($q, $w);
    $scores = [
        'greet'    => $is(['hi', 'hello', 'hey', 'good morning', 'good afternoon', 'good evening', 'kumusta', 'kamusta', 'musta', 'maayong buntag', 'maayong hapon', 'maayong gabii', 'start']),
        'bye'      => $is(['bye', 'goodbye', 'see you', 'paalam']),
        'help'     => $is(['help', 'menu', 'options', 'what can you do', 'unsa imong mahimo', 'topics']),
        'rooms'    => $is(['available', 'availability', 'vacant', 'vacancy', 'vacancies', 'rooms', 'room', 'beds', 'bed', 'bedspace', 'bed space', 'slot', 'slots', 'open', 'bakante', 'kwarto', 'kuwarto', 'naay', 'unsay room', 'list of rooms']),
        'rates'    => $is(['rate', 'rates', 'price', 'prices', 'rent', 'monthly', 'how much', 'cost', 'fee', 'fees', 'cheapest', 'affordable', 'budget', 'pila', 'presyo', 'bayranan', 'kuan', 'magkano']),
        'deposit'  => $is(['deposit', 'advance', 'security deposit', 'downpayment', 'down payment', 'how much is the deposit', 'how much deposit', 'deposit amount', 'how much is the advance', 'how much advance', 'advance payment']),
        'reqs'     => $is(['requirements', 'requirement', 'what to bring', 'need to bring', 'what do i need', 'documents', 'document', 'valid id', 'school id', 'kinahanglan', 'dad-on', 'dalhon', 'ihatag']),
        'rules'    => $is(['rules', 'rule', 'curfew', 'visitors', 'visitor', 'guest', 'guests', 'pets', 'pet', 'smoking', 'noise', 'allowed', 'not allowed', 'policy', 'policies', 'balaod', 'bisita', 'bisitahan', 'pwede', 'puwede', 'bawal', 'drinking', 'alcohol', 'overnight', 'sleepover']),
        'pay'      => $is(['pay', 'payment', 'payments', 'payment method', 'how to pay', 'bayad', 'magbayad', 'bayaran', 'receipt', 'receipts', 'cash', 'bank', 'bank transfer', 'schedule', 'due date', 'when to pay']),
        'gcash'    => $is(['gcash', 'g cash', 'online payment', 'pay online', 'remote', 'remotely', 'send money', 'e wallet', 'ewallet', 'pay from home']),
        'due'      => $is(['due date', 'due', 'when do i pay', 'when to pay', 'when should i pay', 'next payment', 'late', 'late payment', 'missed', 'missing payment', 'overdue', 'balance', 'reminder', 'reminders', 'notify', 'notification', 'notifications', 'every month', 'monthly due', 'kanus a', 'kanus-a', 'kanusa', 'kailan magbayad']),
        'safe'     => $is(['safe', 'secure', 'safety', 'legit', 'legitimate', 'scam', 'trust', 'trusted', 'trustworthy', 'privacy', 'personal information', 'my details', 'is it safe']),
        'moveout'  => $is(['move out', 'moving out', 'move-out', 'check out', 'checkout', 'leave', 'leaving', 'notice', 'refund', 'deposit refund', 'return my deposit', 'get my deposit back', 'cancel', 'cancellation', 'cancel reservation', 'how long is the hold', 'hold the bed', 'hold my bed', 'forfeit', 'non refundable', 'nonrefundable', 'refundable', 'reservation fee', 'hold fee']),
        'where'    => $is(['where', 'location', 'address', 'located', 'directions', 'direction', 'map', 'how to get', 'asa', 'diin', 'nasaan', 'inabanga', 'bohol', 'poblacion', 'near']),
        'contact'  => $is(['contact', 'phone', 'number', 'call', 'text', 'email', 'facebook', 'fb', 'messenger', 'hours', 'open hours', 'what time', 'office hours', 'open now', 'reach', 'talk', 'tawag', 'kontak']),
        'landlord' => $is(['landlord', 'landlady', 'owner', 'admin', 'administrator', 'manager', 'who runs', 'who owns', 'in charge', 'boss', 'sir', 'maam', 'mrs', 'tag-iya', 'tag iya']),
        'inquiry'  => $is(['inquire', 'inquiry', 'inquiries', 'message', 'ask', 'reserve', 'reservation', 'book', 'booking', 'apply', 'visit', 'viewing', 'see the room', 'tour']),
        'track'    => $is(['track', 'status', 'reply', 'replied', 'my inquiry', 'check my inquiry', 'inquiry code', 'code']),
        'movein'   => $is(['move in', 'move-in', 'moving in', 'check in', 'check-in', 'process', 'steps', 'how does it work', 'how to rent', 'how to book', 'how to reserve', 'how do i reserve', 'how can i reserve', 'how do i book', 'reserve a room', 'reserve a bed', 'reservation process', 'how reserving works', 'how does reserving work', 'unsaon', 'unsaon pag']),
        'amen'     => $is(['wifi', 'wi fi', 'internet', 'aircon', 'air con', 'fan', 'electric', 'electricity', 'water', 'bathroom', 'cr', 'comfort room', 'toilet', 'kitchen', 'cook', 'cooking', 'laundry', 'parking', 'furnished', 'cabinet', 'amenities', 'amenity', 'included', 'includes', 'inclusion', 'aircon', 'kuryente', 'tubig']),
        'gender'   => $is(['male', 'female', 'boys', 'girls', 'men', 'women', 'co-ed', 'coed', 'mixed', 'lalaki', 'babae', 'gender']),
        'about'    => $is(['about', 'who are you', 'what is this', 'tell me about', 'describe', 'unsa ni']),
        'capacity' => $is(['capacity', 'how many', 'how many rooms', 'how many beds', 'size', 'sharing', 'shared', 'private']),
    ];
    arsort($scores);
    $top = (string)array_key_first($scores);
    $best = (int)$scores[$top];
    // a few intents win ties in a sensible order
    if ($best > 0) {
        foreach (['gcash', 'due', 'moveout', 'deposit', 'track', 'movein', 'amen', 'gender', 'rates', 'rooms'] as $pref) {
            if ($scores[$pref] === $best && $pref !== $top && !in_array($top, ['greet', 'bye'], true)) {
                $top = $pref;
                break;
            }
        }
    }
    // "how much" + a payment word is about rates, "gcash" always about gcash
    if ($scores['safe'] > 0) $top = 'safe';
    elseif ($scores['gcash'] > 0) $top = 'gcash';

    switch ($best > 0 ? $top : 'none') {
        case 'greet':
            return $out('Hello! 👋 I can help with rooms, rates, requirements, house rules, payments and contacting the landlord of **' . $kb['site'] . '**. What would you like to know?', [], $main);

        case 'bye':
            return $out('Thank you for visiting! Come back anytime. 🏠', [chat_act_inquiry()], []);

        case 'help':
            return $out("Here is what I can help with:\n• Available rooms and beds\n• Rates, deposit and advance payment\n• Requirements for new tenants\n• House rules\n• How to pay, including GCash\n• Location and contact details\n• How to inquire or reserve", [], $main);

        case 'rooms':
        case 'capacity':
            if (!$rooms) {
                return $out('The room list is being updated. Please send an inquiry and the landlord will tell you what is open.', [chat_act_inquiry()], ['Contact the landlord']);
            }
            $avail = array_values(array_filter($rooms, fn($r) => $r['status_key'] === 'available'));
            if (!$avail) {
                return $out("All rooms are taken or reserved right now. Send an inquiry and the landlord will tell you as soon as a bed frees up.\n\nTotal rooms listed: " . count($rooms) . '.', [chat_act_inquiry(), chat_act_link('See all rooms', 'rooms.php')], ['Rates', 'Contact the landlord']);
            }
            $beds = array_sum(array_column($avail, 'open_beds'));
            $t = '**' . count($avail) . ' ' . (count($avail) === 1 ? 'room has' : 'rooms have') . ' open space** (' . $beds . ' ' . ($beds === 1 ? 'bed' : 'beds') . " in total):\n" . implode("\n", array_map('chat_rooms_line', array_slice($avail, 0, 6)));
            if (count($avail) > 6) $t .= "\n…and " . (count($avail) - 6) . ' more.';
            return $out($t, [chat_act_link('See all rooms', 'rooms.php'), chat_act_inquiry()], ['Rates', 'Requirements', 'How to pay']);

        case 'rates':
            if (!$rooms) {
                return $out('Rates are shown on the rooms page. Please send an inquiry if the list is empty.', [chat_act_link('Rooms and rates', 'rooms.php'), chat_act_inquiry()], []);
            }
            $rates = array_map(fn($r) => (float)$r['monthly_rate'], $rooms);
            $cheap = $rooms[array_search(min($rates), $rates, true)];
            $t = 'Rent ranges from **' . money_short(min($rates)) . '** to **' . money_short(max($rates)) . "** a month, depending on the room.\n\n" . implode("\n", array_map('chat_rooms_line', array_slice($rooms, 0, 6)));
            $t .= "\n\nThe lowest rate is " . room_title($cheap) . '.';
            if ($kb['advance'] > 0) $t .= ' An advance of ' . $kb['advance'] . ' ' . ($kb['advance'] === 1 ? 'month' : 'months') . ' of rent is collected to secure a room.';
            return $out($t, [chat_act_link('Rates & house rules', 'policies.php'), chat_act_inquiry()], ['Available rooms', 'Requirements', 'How to pay']);

        case 'deposit':
            $deps = array_unique(array_filter(array_map(fn($r) => (float)$r['deposit_amount'], $rooms), fn($d) => $d > 0));
            sort($deps);
            $t = $kb['advance'] > 0
                ? 'To secure a room you pay an **advance of ' . $kb['advance'] . ' ' . ($kb['advance'] === 1 ? 'month' : 'months') . ' of rent** once your reservation is confirmed.'
                : 'No advance payment is required, but the landlord confirms reservations personally.';
            $t .= $deps ? "\nThe refundable security deposit is " . implode(' or ', array_map('money_short', $deps)) . ' depending on the room.' : "\nA refundable security deposit may apply, depending on the room.";
            foreach ($kb['payments'] as $l) {
                if (preg_match('/deposit|advance/i', $l)) $t .= "\n• " . $l;
            }
            return $out($t, [chat_act_link('Rates & house rules', 'policies.php')], ['How to pay', 'Requirements', 'Available rooms']);

        case 'reqs':
            if (!$kb['requirements']) {
                return $out('Please ask the landlord what to bring. You can send an inquiry any time.', [chat_act_inquiry()], []);
            }
            return $out("**What new tenants need:**\n" . implode("\n", array_map(fn($l) => '• ' . $l, $kb['requirements'])), [chat_act_link('Rates & house rules', 'policies.php'), chat_act_inquiry()], ['How to pay', 'House rules', 'Available rooms']);

        case 'rules':
            if (!$kb['rules']) {
                return $out('The landlord will share the house rules when you inquire.', [chat_act_inquiry()], []);
            }
            // answer narrowly when the question mentions a specific topic
            $hits = chat_search_lines($q, $kb['rules']);
            if ($hits && !chat_has($q, ['rules', 'rule', 'house rules', 'policies', 'policy'])) {
                return $out("From the house rules:\n" . implode("\n", array_map(fn($l) => '• ' . $l, $hits)), [chat_act_link('All house rules', 'policies.php')], ['Requirements', 'How to pay']);
            }
            return $out("**House rules:**\n" . implode("\n", array_map(fn($l) => '• ' . $l, array_slice($kb['rules'], 0, 10))), [chat_act_link('All house rules', 'policies.php')], ['Requirements', 'How to pay', 'Available rooms']);

        case 'gcash':
            $t = $kb['gcash'] !== ''
                ? 'Yes, you can pay by **GCash** from anywhere. Send the amount to **' . $kb['gcash'] . '**' . ($kb['landlord'] !== '' ? ' (' . $kb['landlord'] . ')' : '') . ", then tap the button below and report your GCash reference number. You get a report code (use it to check the status any time). The landlord checks it, you receive a message, and an official receipt is issued."
                : 'GCash payments are accepted, but the landlord has not posted a number yet. Please contact the landlord first.';
            return $out($t, [['type' => 'gcash', 'label' => 'Report a GCash payment'], chat_act_inquiry('Message the landlord')], ['How to pay', 'Contact the landlord']);

        case 'pay':
            $t = "**How to pay:**\n• Cash, handed to the landlord\n• GCash, from anywhere" . ($kb['gcash'] !== '' ? ' (' . $kb['gcash'] . ')' : '') . "\n• Bank transfer, if the landlord agrees\nAn official receipt is issued for every payment.";
            if ($kb['payments']) $t .= "\n\n**Terms:**\n" . implode("\n", array_map(fn($l) => '• ' . $l, array_slice($kb['payments'], 0, 5)));
            return $out($t, [['type' => 'gcash', 'label' => 'Pay with GCash'], chat_act_link('Rates & house rules', 'policies.php')], ['Requirements', 'Available rooms']);

        case 'due':
            $t = "**When rent is due:** one month after you move in, then every month on the same day. If you moved in on the 5th, your rent is due every 5th.";
            $t .= "\n\n**Reminders:** you get a message by e-mail or Messenger a few days before the due date, on the due date, and again if a payment is missing or only partly paid. It is never sent twice for the same due date.";
            $t .= "\n\nIf you missed a payment, the unpaid amount stays on your account as a balance until it is paid." . ($kb['gcash'] !== '' ? ' You can pay by cash or GCash.' : '');
            return $out($t, [['type' => 'gcash', 'label' => 'Pay with GCash'], chat_act_link('Check my inquiry', 'track.php')], ['How to pay', 'Contact the landlord']);

        case 'moveout':
            $life = $kb['life'] ?? ['fee' => 0, 'hold_days' => 3, 'notice_days' => 30, 'fee_refundable' => false];
            $t = "**Moving out:** please give **" . (int)$life['notice_days'] . " days' notice**. On your last day we check the room together. Your security deposit is returned after that, minus any unpaid rent or damages, and you get a written summary and receipt.";
            $t .= "\n\n**Cancelling a reservation:** " . ($life['fee'] > 0 ? 'the reservation fee of ' . money($life['fee']) . ($life['fee_refundable'] ? ' is refundable.' : ' is not refundable, but it counts toward your first month if you do move in.') : 'payments are refunded according to what the landlord agrees with you.');
            $t .= "\nA held bed is kept for " . (int)$life['hold_days'] . " days. If the reservation payment is not received by then, the hold ends and the bed is released.";
            return $out($t, [chat_act_inquiry('Ask the landlord')], ['Requirements', 'How to pay', 'Contact the landlord']);

        case 'safe':
            return $out("**Your payments and details are protected:**\n• Every GCash report gets a code, and nothing is counted until the landlord checks it against the GCash history\n• You get an official receipt for every confirmed payment, and you can check a report's status any time\n• Rooms are only held when the landlord confirms your reservation, never by an automatic reply\n• Your details are kept only to answer your inquiry and manage your stay, and you can ask the landlord to erase them\n• Passwords and payment records are never shown to other people", [['type' => 'gcash', 'label' => 'Pay with GCash']], ['How to pay', 'Requirements', 'Contact the landlord']);

        case 'where':
            $t = $kb['address'] !== '' ? 'We are at **' . $kb['address'] . '**.' : 'The address is on our contact section.';
            if ($kb['hours'] !== '') $t .= "\nVisiting or calling? The landlord answers: " . $kb['hours'] . '.';
            if ($kb['phone'] !== '') $t .= "\nCall or text **" . $kb['phone'] . '** for directions.';
            return $out($t, [chat_act_inquiry('Ask for directions')], ['Contact the landlord', 'Available rooms']);

        case 'landlord':
            $t = ($kb['landlord'] !== '' ? '**' . $kb['landlord'] . '** is the landlord and manages ' . $kb['site'] . ' personally.' : 'The landlord manages ' . $kb['site'] . ' personally.')
               . "\n\n" . chat_contact_text($kb)
               . "\n\nFor privacy I can't share login details or tenant records.";
            return $out($t, [chat_act_inquiry('Message the landlord')], ['Available rooms', 'Rates', 'How to pay']);

        case 'contact':
            $c = chat_contact_text($kb);
            return $out($c !== '' ? "**Contact the landlord:**\n" . $c : 'Please use the inquiry form and the landlord will reply.', [chat_act_inquiry('Send an inquiry')], ['Location', 'Available rooms']);

        case 'track':
            return $out("After you send an inquiry you get a **code**. Open **Check my inquiry**, enter the code and the contact number you used, and you can read the landlord's reply and send follow-up messages.", [chat_act_link('Check my inquiry', 'track.php'), chat_act_inquiry('Send a new inquiry')], ['Contact the landlord']);

        case 'inquiry':
        case 'movein':
            $life = $kb['life'] ?? ['fee' => 0, 'hold_days' => 3, 'notice_days' => 30, 'fee_refundable' => false];
            $t = "**How reserving works:**\n1. Send an inquiry. You get an instant answer: confirmed if the room has an open bed, or not confirmed with other rooms that do\n2. The landlord holds your bed for **" . (int)$life['hold_days'] . " days** while you pay the reservation " . ($life['fee'] > 0 ? 'fee of **' . money($life['fee']) . '**' : 'amount')
               . "\n3. Pay by cash" . ($kb['gcash'] !== '' ? ' or GCash' : '') . ". Paying confirms your reservation. If it is not paid in time, the bed is released\n4. Move in: pay the deposit and the rest of the first month, then we check the room together\n5. Pay rent every month on your move-in date. We remind you by e-mail or Messenger\n6. Move out with " . (int)$life['notice_days'] . " days' notice. The deposit comes back minus unpaid rent or damages";
            return $out($t, [chat_act_inquiry('Send an inquiry'), chat_act_link('Check my inquiry', 'track.php')], ['Requirements', 'How to pay', 'Rates']);

        case 'amen':
            $words = ['wifi', 'internet', 'aircon', 'air con', 'fan', 'electric', 'water', 'bathroom', 'cr', 'comfort room', 'kitchen', 'laundry', 'parking', 'cabinet', 'furnished'];
            $wanted = array_values(array_filter($words, fn($w) => chat_has($q, [$w]) > 0));
            $with = [];
            foreach ($rooms as $r) {
                $hay = mb_strtolower(($r['amenities'] ?? '') . ' ' . ($r['description'] ?? ''));
                foreach ($wanted as $w) {
                    if ($w !== '' && str_contains($hay, $w)) { $with[(int)$r['id']] = $r; }
                }
            }
            if ($wanted && $with) {
                return $out('Yes. These rooms mention ' . implode(' / ', $wanted) . ":\n" . implode("\n", array_map('chat_rooms_line', array_slice($with, 0, 6))), [chat_act_link('See all rooms', 'rooms.php')], ['Rates', 'Available rooms']);
            }
            $all = [];
            foreach ($rooms as $r) {
                foreach (preg_split('/[,;\n]+/', (string)($r['amenities'] ?? '')) ?: [] as $a) {
                    $a = trim($a);
                    if ($a !== '') $all[mb_strtolower($a)] = $a;
                }
            }
            if ($wanted && $all) {
                return $out("I don't see that listed for any room. What the rooms include: " . implode(', ', array_slice(array_values($all), 0, 12)) . ".\n\nPlease ask the landlord to be sure.", [chat_act_inquiry('Ask the landlord')], ['Available rooms', 'Rates']);
            }
            return $out($all ? 'Rooms include: ' . implode(', ', array_slice(array_values($all), 0, 12)) . '.' : 'Room inclusions are shown on each room page. Ask the landlord if something is not listed.', [chat_act_link('See all rooms', 'rooms.php'), chat_act_inquiry()], ['Rates', 'Available rooms']);

        case 'gender':
            if (!$rooms) return $out('Please ask the landlord which rooms are for men, women or anyone.', [chat_act_inquiry()], []);
            $g = ['male' => [], 'female' => [], 'any' => []];
            foreach ($rooms as $r) { $g[$r['gender_policy']][] = room_title($r); }
            $t = '';
            foreach (['female' => 'Female only', 'male' => 'Male only', 'any' => 'Open to anyone'] as $k => $l) {
                if (!empty($g[$k])) $t .= '• **' . $l . ':** ' . implode(', ', array_slice($g[$k], 0, 8)) . "\n";
            }
            return $out(trim($t), [chat_act_link('See all rooms', 'rooms.php')], ['Available rooms', 'Rates']);

        case 'about':
            $t = $kb['about'] !== '' ? $kb['about'] : $kb['tagline'];
            return $out($t, [chat_act_link('See rooms', 'rooms.php'), chat_act_inquiry()], ['Available rooms', 'Rates', 'Location']);
    }

    /* ---- last try: search the policy text and room details ---- */
    $pool = array_merge($kb['rules'], $kb['requirements'], $kb['payments'], preg_split('/(?<=[.!?])\s+/', (string)$kb['about']) ?: []);
    $hits = chat_search_lines($q, $pool);
    if ($hits) {
        return $out("Here is what I found:\n" . implode("\n", array_map(fn($l) => '• ' . $l, $hits)), [chat_act_link('Rates & house rules', 'policies.php'), chat_act_inquiry('Ask the landlord')], ['Available rooms', 'Contact the landlord']);
    }
    $r = $out("I'm not sure about that one. 🤔 The landlord can answer it directly.\n\n" . ($kb['phone'] !== '' ? 'Call or text **' . $kb['phone'] . '**' . ($kb['hours'] !== '' ? ' (' . $kb['hours'] . ')' : '') . ', or send an inquiry.' : 'Please send an inquiry.') . "\n\nYou can also ask me about rooms, rates, requirements, house rules, payments or location.", [chat_act_inquiry('Message the landlord')], $main);
    $r['social_only'] = true;   // nothing matched: only a fallback
    return $r;
}

/** Lines that share meaningful words with the question (best first, at most 3). */
function chat_search_lines(string $q, array $lines): array
{
    static $stop = ['the', 'and', 'for', 'are', 'you', 'your', 'can', 'what', 'when', 'does', 'have', 'with', 'this', 'that', 'there', 'about', 'how', 'any', 'allowed', 'rules', 'rule', 'house', 'please', 'tell', 'much', 'many', 'unsa', 'ang', 'mga', 'kay', 'naa', 'ba'];
    $words = array_values(array_filter(preg_split('/\s+/', $q) ?: [], fn($w) => mb_strlen($w) >= 3 && !in_array($w, $stop, true)));
    if (!$words) return [];
    $scored = [];
    foreach ($lines as $i => $l) {
        $lc = mb_strtolower($l);
        $s = 0;
        foreach ($words as $w) {
            $stem = mb_strlen($w) > 5 ? mb_substr($w, 0, mb_strlen($w) - 1) : $w;
            if (str_contains($lc, $stem)) $s++;
        }
        if ($s > 0) $scored[$i] = $s;
    }
    arsort($scored);
    return array_map(fn($i) => $lines[$i], array_slice(array_keys($scored), 0, 3));
}
