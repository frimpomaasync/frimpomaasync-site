<?php
declare(strict_types=1);

/**
 * The HTML half of every email is built from the text half and adds
 * nothing to it. These cases prove the reading rules (labels, steps, the
 * button, the signature), that every character is escaped, and that the
 * two halves say the same thing.
 */

use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Services\MailHtml;

$sample = "Hello Dana,\n\n"
    . "Thank you for telling me about Fictional Behavioral Health LLC's denials. The next step is a free review.\n\n"
    . "WHAT TO DO NOW\n"
    . "Open the link below and tap \"Open the terms\".\n\n"
    . "https://staging.frimpomaasync.com/soft-appeals-preferences.php?t=abc123\n\n"
    . "WHAT HAPPENS AFTER THAT\n"
    . "1. Two short agreements go to the person you name.\n"
    . "2. Your secure route opens.\n"
    . "3. The review. It does not produce 20 finished appeals.\n\n"
    . "Do not reply with claim or patient information. Replies go to softappeals@frimpomaasync.com.\n\n"
    . "Nana Frimpongmaa\n"
    . "Founder, Soft Appeals\n";

return [

    'labels become headlines, steps become a list, the URL becomes the button, the close becomes a signature' =>
        static function (Bootstrap $app, Database $db) use ($sample): void {
            $html = MailHtml::render($sample, 'A subject');
            Expect::true(str_contains($html, '>What to do now<'), 'a capital label is set in sentence case as a headline');
            Expect::true(str_contains($html, '>What happens after that<'), 'and the second one');
            Expect::true(str_contains($html, '<ol'), 'numbered lines are a list');
            Expect::true(str_contains($html, '<li style="margin:0 0 10px;padding-left:4px;">Two short agreements go to the person you name.</li>'), 'without their numbers, which the list draws');
            Expect::true(str_contains($html, 'href="https://staging.frimpomaasync.com/soft-appeals-preferences.php?t=abc123"'), 'the link is the button');
            Expect::true(str_contains($html, '>Open</a>'), 'labelled Open');
            Expect::true(str_contains($html, 'copy this into your browser'), 'and printed for clients that strip buttons');
            Expect::true(str_contains($html, '<div style="font-weight:600;">Nana Frimpongmaa</div>'), 'her name is the signature');
            Expect::true(str_contains($html, 'Founder, Soft Appeals'), 'with the title under it');
            Expect::true(str_contains($html, 'SOFT APPEALS'), 'the wordmark is text');
            Expect::false(str_contains($html, '<img'), 'no image anywhere');
            Expect::false(str_contains($html, 'fonts.googleapis') || str_contains($html, '@font-face') || str_contains($html, 'http://'), 'nothing remote, nothing insecure');
            Expect::true(str_contains($html, '<title>A subject</title>'), 'the subject is the title');
        },

    'every character is escaped and nothing from outside the text appears' =>
        static function (Bootstrap $app, Database $db): void {
            $html = MailHtml::render("Hello <b>Dana</b>,\n\nA line with & and \"quotes\" and <script>alert(1)</script>.\n\nNana Frimpongmaa\nSoft Appeals\n", 'x & y');
            Expect::false(str_contains($html, '<script>'), 'a tag in the text is not a tag in the HTML');
            Expect::true(str_contains($html, '&lt;script&gt;'), 'it is shown as text');
            Expect::true(str_contains($html, 'Hello &lt;b&gt;Dana&lt;/b&gt;,'), 'the greeting too');
            Expect::true(str_contains($html, '<title>x &amp; y</title>'), 'and the subject');
        },

    'a URL inside a paragraph is a link, not a button, and a plain paragraph stays one' =>
        static function (Bootstrap $app, Database $db): void {
            $html = MailHtml::render("Hello there,\n\nSign in at https://frimpomaasync.com/soft-appeals-room with this address.\n\nNana Frimpongmaa\nSoft Appeals\n", 's');
            Expect::true(str_contains($html, '<a href="https://frimpomaasync.com/soft-appeals-room"'), 'linked');
            Expect::false(str_contains($html, '>Open</a>'), 'but not a button');
            Expect::same(0, substr_count($html, '<ol'), 'no list is invented');
        },

    'the footer sentence is carried and a text with no signature is still whole' =>
        static function (Bootstrap $app, Database $db): void {
            $html = MailHtml::render("Good morning. Here is what needs attention today.\n\n2 inquiries need fit review\n1 agreement needs your countersignature\n", 'Digest', 'Counts only.');
            Expect::true(str_contains($html, 'Counts only.'), 'the footer is there');
            Expect::true(str_contains($html, '2 inquiries need fit review'), 'the lines are there');
            Expect::true(str_contains($html, 'frimpomaasync.com/soft-appeals'), 'and the standing footer link');
        },
];
