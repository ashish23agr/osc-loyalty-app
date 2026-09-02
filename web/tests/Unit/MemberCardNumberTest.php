<?php

namespace Tests\Unit;

use App\Support\Members\MemberCardNumber;
use PHPUnit\Framework\TestCase;

/**
 * C11: the club card number is derived from the account id rather than stored,
 * which makes a search for one an exact primary-key lookup.
 *
 * That only holds if parsing is strict. A parser that keeps whatever digits it
 * finds turns any search term containing a number into a primary-key lookup for
 * an unrelated member.
 */
class MemberCardNumberTest extends TestCase
{
    public function test_it_formats_an_account_id_as_a_card_number(): void
    {
        $this->assertSame('0000-0012', MemberCardNumber::format(12));
        $this->assertSame('0041-2287', MemberCardNumber::format(412287));
    }

    public function test_it_reads_a_card_number_back_however_it_is_written(): void
    {
        foreach (['0041-2287', '0041 2287', '412287', ' 412287 '] as $written) {
            $this->assertSame(412287, MemberCardNumber::parse($written), $written);
        }
    }

    public function test_format_and_parse_are_inverses(): void
    {
        foreach ([1, 12, 999, 412287, 99999999] as $id) {
            $this->assertSame($id, MemberCardNumber::parse(MemberCardNumber::format($id)));
        }
    }

    /**
     * The bug this exists to prevent.
     *
     * "SP4 6AB" contains the digits 4 and 6. A parser that strips non-digits
     * reads it as account 46 and the search returns whoever that is — a
     * different member's record, produced by typing a postcode.
     */
    public function test_a_search_term_that_merely_contains_digits_is_not_a_card_number(): void
    {
        foreach ([
            'SP4 6AB',      // a postcode
            'sp46ab',
            'BH21 4RG',
            'D-090311',     // a legacy Dynamics number, which has its own branch
            'D-118422',
            'flat 2',
            'tomw',
            'o2',
        ] as $term) {
            $this->assertNull(
                MemberCardNumber::parse($term),
                $term.' must not be read as a club card number.',
            );
        }
    }

    public function test_it_refuses_nonsense(): void
    {
        foreach (['', '   ', '0000-0000', '0', 'abc', '1234567890123'] as $term) {
            $this->assertNull(MemberCardNumber::parse($term), $term);
        }
    }
}
