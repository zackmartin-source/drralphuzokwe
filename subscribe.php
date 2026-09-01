<?php
/* ---------------------------------------------------------------------------
   The sign up endpoint for drralphuzokwe.com, written for Hostinger's PHP.

   It does four things and nothing else:
     1. checks the address is real enough to send to
     2. writes it to a file the site cannot serve
     3. sends the reader a welcome note in Ralph's name
     4. tells Ralph a new reader arrived

   SET THESE TWO LINES BEFORE GOING LIVE. The mailbox in FROM_ADDRESS must
   exist in Hostinger's email panel, otherwise the welcome note is rejected by
   the receiving server as a forgery.
   --------------------------------------------------------------------------- */
$FROM_ADDRESS = 'hello@drralphuzokwe.com';        // a real mailbox on this domain
$NOTIFY       = 'hello@drralphuzokwe.com';        // where Ralph wants to be told
$FROM_NAME    = 'Dr. Ralph Tyga Uzokwe';

$LIST = __DIR__ . '/../subscribers.csv';          // above the web root, unreachable by URL
if (!is_writable(dirname($LIST))) $LIST = __DIR__ . '/private/subscribers.csv';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function done($ok, $message, $code = 200) {
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') done(false, 'Use the form on the site.', 405);

/* the form posts JSON; a browser without scripting posts fields */
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

$email  = trim((string)($data['email'] ?? ''));
$name   = trim((string)($data['name'] ?? ''));
$intent = trim((string)($data['intent'] ?? 'letter'));
$trap   = trim((string)($data['website'] ?? ''));   // hidden field, humans leave it empty

if ($trap !== '') done(true, 'Thank you.');          // a bot, quietly accepted and dropped
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) done(false, 'That address does not look right.', 422);
if (strlen($email) > 190) done(false, 'That address is too long.', 422);

/* one address, a few times a minute, is plenty */
$bucket = sys_get_temp_dir() . '/ru_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x');
$hits = file_exists($bucket) ? (int)file_get_contents($bucket) : 0;
if (file_exists($bucket) && time() - filemtime($bucket) < 60 && $hits >= 5) {
    done(false, 'One moment, then try again.', 429);
}
file_put_contents($bucket, (time() - @filemtime($bucket) < 60) ? $hits + 1 : 1);

/* keep the list */
@mkdir(dirname($LIST), 0750, true);
$row = [date('c'), $email, $name, $intent, $_SERVER['REMOTE_ADDR'] ?? '', substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 120)];
$fh = @fopen($LIST, 'a');
if ($fh) { flock($fh, LOCK_EX); fputcsv($fh, $row); flock($fh, LOCK_UN); fclose($fh); }

$headers = "From: " . mb_encode_mimeheader($FROM_NAME) . " <$FROM_ADDRESS>\r\n"
         . "Reply-To: $FROM_ADDRESS\r\n"
         . "MIME-Version: 1.0\r\n"
         . "Content-Type: text/plain; charset=UTF-8\r\n"
         . "X-Mailer: drralphuzokwe.com\r\n";

/* the welcome note, in his voice, not a receipt */
$welcome = <<<TXT
Thank you for joining me.

You will hear from me when a book is close, when a chapter is worth reading
early, and when I am speaking somewhere you can reach. Nothing else, and never
to anyone I have not written to myself.

My work sits on one idea: the world does not stand still for anyone, and the
people who study what is emerging do better than the people who mourn what is
disappearing. Whichever book brought you here, that is the thread running
through all of them.

If you want to start reading now, everything is here:
https://drralphuzokwe.com/books/

And if you would rather hear me talk through each book first, I recorded a full
talk on every one of them:
https://drralphuzokwe.com/listen/

Warmly,
Dr. Ralph Tyga Uzokwe
Author, entrepreneur and speaker
https://drralphuzokwe.com/
TXT;

$subjects = [
    'letter'   => 'Welcome, and thank you for joining me',
    'speaking' => 'Thank you for the enquiry',
    'popup'    => 'Welcome, and thank you for joining me',
];
$subject = $subjects[$intent] ?? $subjects['letter'];

$sentToReader = @mail($email, $subject, $welcome, $headers);

/* and a word to Ralph */
$labels = ['letter' => 'the first chapter form', 'speaking' => 'the speaking page', 'popup' => 'the welcome popup'];
$where = $labels[$intent] ?? $intent;
$note = "A new reader signed up through $where.\n\n"
      . "Email: $email\n"
      . ($name !== '' ? "Name: $name\n" : '')
      . "When: " . date('l j F Y, H:i') . "\n\n"
      . "The full list is in subscribers.csv on the server.\n";
@mail($NOTIFY, 'New sign up: ' . $email, $note, $headers);

done(true, $sentToReader
    ? 'Thank you. Check your inbox, the welcome note is on its way.'
    : 'Thank you. You are on the list.');
