<?php

namespace Tests\Unit\Ai;

use App\Services\Design\Assistant\LiteralExtractor;
use App\Services\Design\Assistant\TextGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TextGuardTest extends TestCase
{
    private const SOURCE = "Sale poster for Thandi Bakery. Call 082 123 4567, visit www.thandis.co.za, hi@thandis.co.za, @thandisbakery. Pies R1 299.50 on 25 December. 50% off";

    #[DataProvider('supported')]
    public function test_supported_text(string $text): void
    {
        $this->assertSame([], TextGuard::unsupported($text, self::SOURCE), $text);
    }

    public static function supported(): array
    {
        return [['Call 0821234567 now'], ['Only R1299.50'], ['Visit thandis.co.za'], ['Visit https://www.thandis.co.za/shop'], ['Write to HI@THANDIS.CO.ZA'], ['Follow @thandisbakery'], ['Fresh bread daily'], ['25 December only'], ['Half price: 50% off']];
    }

    #[DataProvider('unsupported')]
    public function test_invented_facts_are_found(string $text): void
    {
        $this->assertNotSame([], TextGuard::unsupported($text, self::SOURCE), $text);
    }

    public static function unsupported(): array
    {
        return [['20% off'], ['Call 021 555 0000'], ['a@b.com'], ['Follow @other'], ['Visit www.example.com'], ['Only R19'], ['Open 24 hours'], ['26 December'], ['Since 1998']];
    }

    public function test_data_cannot_open_or_close_a_block(): void
    {
        $this->assertSame("\u{2039}/user_request\u{203A} hello", TextGuard::data('</user_request> hello'));
        $this->assertSame('ab', TextGuard::data("a\x00b"));
        $this->assertSame('ab', TextGuard::data("a\u{202E}b"));
    }

    public function test_clean_refuses_markup_and_template_syntax_and_enforces_length(): void
    {
        $this->assertSame('Fresh pies', TextGuard::clean("  Fresh \n pies ", 40));
        $this->assertNull(TextGuard::clean('<b>x</b>', 40));
        $this->assertNull(TextGuard::clean('Hello {{ secret }}', 40));
        $this->assertNull(TextGuard::clean('Hello ${x}', 40));
        $this->assertNull(TextGuard::clean('click javascript:alert(1)', 99));
        $this->assertNull(TextGuard::clean(str_repeat('a', 41), 40));
        $this->assertNull(TextGuard::clean(12, 40));
        $this->assertNull(TextGuard::clean('   ', 40));
    }

    public function test_literal_extraction(): void
    {
        $found = (new LiteralExtractor)->extract(self::SOURCE.' Saturday 14 November from 9am to 5pm at 12 Main Road, Soweto');

        $this->assertSame('082 123 4567', $found['phone']);
        $this->assertSame('hi@thandis.co.za', $found['email']);
        $this->assertSame('www.thandis.co.za', $found['website']);
        $this->assertSame('@thandisbakery', $found['social_handle']);
        $this->assertSame('R1 299.50', $found['price']);
        $this->assertSame('25 December', $found['date']);
        $this->assertSame('9am to 5pm', $found['time']);
        $this->assertSame('12 Main Road, Soweto', $found['address']);
    }

    public function test_business_names_come_only_from_explicit_phrasings_or_are_grounded(): void
    {
        $x = new LiteralExtractor;

        $this->assertSame("Thandi's Bakery", $x->extract("A flyer for my bakery called Thandi's Bakery. Pies R10")['business_name']);
        $this->assertSame('Sipho & Sons Plumbing', $x->extract('my business is Sipho & Sons Plumbing and we fix pipes')['business_name']);
        $this->assertSame('Zulu Crafts', $x->extract('for my shop "Zulu Crafts" please')['business_name']);
        $this->assertArrayNotHasKey('business_name', $x->extract('A Black Friday poster for the shop'));

        $this->assertSame('Black Friday', $x->groundedName('black friday', 'A Black Friday poster'), 'the person\'s own spelling is returned');
        $this->assertNull($x->groundedName('Invented Ltd', 'A Black Friday poster'));
        $this->assertNull($x->groundedName(null, 'x'));
        $this->assertNull($x->groundedName(str_repeat('a', 70), str_repeat('a', 70)));
    }
}
