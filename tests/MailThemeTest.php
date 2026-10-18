<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Mail\Mails;
use AlfaSix\Alfakit\Pages\MailPreview;

uses(AlfaSix\AlfakitWorkflows\Tests\TestCase::class);

it('renders its mails in the AlfaKit mail theme, right to left in Arabic', function (string $key): void {
    config()->set('alfakit.mail.name', 'Acme Mail');
    config()->set('alfakit.mail.primary_color', '#0f766e');

    $definition = Mails::get($key);

    expect($definition)->not->toBeNull()
        ->and($definition?->isEditable())->toBeTrue()
        ->and($definition?->getMergeTags())->not->toBe([]);

    $ltr = MailPreview::renderMail($definition, 'en');
    $rtl = MailPreview::renderMail($definition, 'ar');
    $dark = MailPreview::renderMail($definition, 'en', dark: true);

    expect($ltr['subject'])->not->toBe('')
        ->and($ltr['html'])->toContain('Acme Mail')->toContain('#0f766e')->toContain('dir="ltr"')->toContain('lang="en"')
        ->and($rtl['html'])->toContain('Acme Mail')->toContain('dir="rtl"')->toContain('lang="ar"')
        ->and($dark['html'])->toContain('content="dark"');
})->with(['workflows.notification']);

it('renders the default template of its editable mails with sample data', function (string $key): void {
    $definition = Mails::get($key);

    expect($definition)->not->toBeNull();

    $unknown = AlfaSix\Alfakit\Mail\EditableTemplates::unknownMergeTags($definition, $definition->getDefaultSubject(), $definition->getDefaultBody());
    $rendered = AlfaSix\Alfakit\Mail\EditableTemplates::render($key, $definition->getDefaultSubject(), (string) $definition->getDefaultBody(), $definition->getSampleData());

    expect($unknown)->toBe([])
        ->and($rendered->subject)->not->toContain('{{')
        ->and($rendered->html->toHtml())->not->toContain('data-type="mergeTag"');
})->with(['workflows.notification']);
