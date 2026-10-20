<?php
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function chat_json(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!is_post()) {
    chat_json(['ok' => false, 'text' => 'Please use the chat window on the website.'], 405);
}
$sent = (string)($_POST['_csrf'] ?? '');
if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
    chat_json(['ok' => false, 'text' => 'Your session expired. Please refresh the page and try again.', 'actions' => [], 'chips' => []], 419);
}
if (throttle_count('chat', 1) >= 30) {
    chat_json(['ok' => true, 'text' => 'You are sending messages very quickly. Please wait a moment and try again.', 'actions' => [], 'chips' => []]);
}
throttle_hit('chat');

$reply = chatbot_reply(post_str('q', 300), chatbot_knowledge());
chat_json(['ok' => true] + $reply);
