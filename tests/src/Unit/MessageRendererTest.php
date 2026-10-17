<?php

declare(strict_types=1);

namespace Drupal\Tests\klaxon\Unit;

use Drupal\Core\Utility\Token;
use Drupal\Tests\UnitTestCase;
use Drupal\klaxon\Alert\MessageRenderer;
use Drupal\klaxon\Entity\AlertInterface;
use Drupal\klaxon\Reading;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Messages are plain text, which every channel escapes for itself.
 */
#[CoversClass(MessageRenderer::class)]
#[Group('klaxon')]
final class MessageRendererTest extends UnitTestCase {

  /**
   * An apostrophe arrives as one, not as `&#039;`.
   *
   * `t()` escapes what its placeholders carry, for a page; Slack and Telegram
   * showed the escape. So does a `<` in a row survive, as text.
   */
  public function testTheDefaultMessageIsPlainText(): void {
    $message = $this->renderer()->render($this->alert(), new Reading(rows: ['a' => ['label' => "Vallic: it's <late> & slow"]]), 'warning');

    $this->assertSame("A machine's clock is off matched 1.\n\n- Vallic: it's <late> & slow", $message->body);
    $this->assertSame("A machine's clock is off", $message->subject);

    $recovered = $this->renderer()->renderRecovery($this->alert(), new Reading(value: 0));
    $this->assertSame("Recovered: A machine's clock is off", $recovered->subject);
    $this->assertStringStartsWith("A machine's clock is off is back to normal.", $recovered->body);
  }

  /**
   * A template's own values and its tokens come out plain too.
   */
  public function testTemplateIsPlainText(): void {
    $message = $this->renderer()->render(
      $this->alert(subject: '[klaxon:label] for [node:title]', body: '[klaxon:rows]'),
      new Reading(rows: ['a' => ['label' => 'Vallic <team>']]),
      'warning',
    );

    $this->assertSame("A machine's clock is off for O'Brien & Co", $message->subject);
    $this->assertSame('- Vallic <team>', $message->body);
  }

  /**
   * The renderer, with tokens replaced as the token service escapes them.
   */
  private function renderer(): MessageRenderer {
    $token = $this->createMock(Token::class);
    $token->method('replace')->willReturnCallback(static fn (string $text): string => str_replace('[node:title]', 'O&#039;Brien &amp; Co', $text));

    return new MessageRenderer($token, $this->getStringTranslationStub());
  }

  /**
   * An alert labelled with an apostrophe.
   */
  private function alert(string $subject = '', string $body = ''): AlertInterface {
    $alert = $this->createMock(AlertInterface::class);
    $alert->method('label')->willReturn("A machine's clock is off");
    $alert->method('getSubjectTemplate')->willReturn($subject);
    $alert->method('getBodyTemplate')->willReturn($body);

    return $alert;
  }

}
