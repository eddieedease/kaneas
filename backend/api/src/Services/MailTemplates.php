<?php

declare(strict_types=1);

namespace Kaneas\Services;

use Kaneas\Core\Config;

/** Localized transactional mails (nl default, en). */
final class MailTemplates
{
    private const STRINGS = [
        'nl' => [
            'invite.subject' => '{inviter} nodigt je uit voor het bord "{board}"',
            'invite.body' => "Hallo,\n\n{inviter} heeft je uitgenodigd om samen te werken aan het Kaneas-bord \"{board}\".\n\nMaak een account aan met dit e-mailadres om toegang te krijgen:\n{url}",
            'added.subject' => 'Je bent toegevoegd aan het bord "{board}"',
            'added.body' => "Hallo {name},\n\n{inviter} heeft je toegevoegd aan het Kaneas-bord \"{board}\".\n\nOpen het bord:\n{url}",
            'test.subject' => 'Kaneas testmail',
            'test.body' => "Dit is een testbericht van Kaneas. Je e-mailinstellingen werken.",
        ],
        'en' => [
            'invite.subject' => '{inviter} invited you to the board "{board}"',
            'invite.body' => "Hello,\n\n{inviter} invited you to collaborate on the Kaneas board \"{board}\".\n\nCreate an account with this email address to get access:\n{url}",
            'added.subject' => 'You were added to the board "{board}"',
            'added.body' => "Hello {name},\n\n{inviter} added you to the Kaneas board \"{board}\".\n\nOpen the board:\n{url}",
            'test.subject' => 'Kaneas test mail',
            'test.body' => "This is a test message from Kaneas. Your mail settings work.",
        ],
    ];

    /** @return array{0:string,1:string} [subject, text body] */
    public static function render(string $template, string $locale, array $vars = []): array
    {
        $strings = self::STRINGS[$locale] ?? self::STRINGS['nl'];
        $replace = [];
        foreach ($vars as $key => $value) {
            $replace['{' . $key . '}'] = (string) $value;
        }
        return [
            strtr($strings[$template . '.subject'], $replace),
            strtr($strings[$template . '.body'], $replace),
        ];
    }

    /** Absolute URL to a frontend route, based on app.url written by the installer. */
    public static function appUrl(string $path = ''): string
    {
        return rtrim((string) Config::get('app.url', ''), '/') . '/' . ltrim($path, '/');
    }
}
