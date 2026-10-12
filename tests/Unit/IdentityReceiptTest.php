<?php

namespace Tests\Unit;

use App\Security\IdentityReceipt;
use App\Security\IdentityTransitionConflict;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IdentityReceiptTest extends TestCase
{
    private function binding(): array
    {
        return ['lineage' => 'd03ffae9-fd9f-49ab-8f63-c2a08a5db733', 'epoch' => 4,
            'boundary' => 'advertiser', 'credential' => str_repeat('a', 64), 'principal' => 27,
            'lifecycle' => 3, 'context' => str_repeat('b', 64), 'generation' => str_repeat('c', 64)];
    }

    public function test_document_and_transition_are_distinct_bounded_signed_evidence(): void
    {
        $signer = new IdentityReceipt(str_repeat('k', 32), fn () => 1000);
        $binding = $this->binding();
        $document = $signer->issue($binding, 'document', 1100);
        $this->assertSame($binding, $signer->verify($document, $binding, 'document'));
        $challenge = [...$binding, 'nonce' => str_repeat('d', 64)];
        $transition = $signer->issue($challenge, 'transition', 1100);
        $this->assertSame($challenge, $signer->verify($transition, $challenge, 'transition'));
        $this->expectException(IdentityTransitionConflict::class);
        $signer->verify($transition, $binding, 'document');
    }

    public static function differentBindings(): array
    {
        return [
            'lineage' => ['lineage', '293d87ef-d09b-4c50-96d7-d12b38d3b738'],
            'superseded epoch' => ['epoch', 5],
            'opposite boundary' => ['boundary', 'internal'],
            'rebound credential' => ['credential', str_repeat('e', 64)],
            'different principal' => ['principal', 28],
            'changed lifecycle' => ['lifecycle', 4],
            'different session context' => ['context', str_repeat('f', 64)],
            'other document generation' => ['generation', str_repeat('0', 64)],
        ];
    }

    #[DataProvider('differentBindings')]
    public function test_a_valid_signature_cannot_substitute_for_current_server_binding(string $field, mixed $value): void
    {
        $signer = new IdentityReceipt(str_repeat('k', 32), fn () => 1000);
        $binding = $this->binding();
        $receipt = $signer->issue($binding, 'document', 1100);
        $current = [...$binding, $field => $value];
        $this->expectException(IdentityTransitionConflict::class);
        $signer->verify($receipt, $current, 'document');
    }

    public static function invalidReceipts(): array
    {
        return ['empty' => [''], 'malformed' => ['a.b.c'], 'oversized' => [str_repeat('a', 4097)]];
    }

    #[DataProvider('invalidReceipts')]
    public function test_malformed_input_is_rejected(string $receipt): void
    {
        $this->expectException(IdentityTransitionConflict::class);
        (new IdentityReceipt(str_repeat('k', 32), fn () => 1000))->read($receipt, 'document');
    }

    public function test_signature_tampering_and_application_key_separation(): void
    {
        $signer = new IdentityReceipt(str_repeat('k', 32), fn () => 1000);
        $receipt = $signer->issue($this->binding(), 'document', 1100);
        foreach ([substr_replace($receipt, '!', 5, 1), $receipt] as $index => $input) {
            try {
                ($index === 0 ? $signer : new IdentityReceipt(str_repeat('j', 32), fn () => 1000))->read($input, 'document');
                $this->fail('Invalid signature accepted.');
            } catch (IdentityTransitionConflict) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_exact_expiration_and_clock_rollback_fail_closed(): void
    {
        $clock = 1000;
        $signer = new IdentityReceipt(str_repeat('k', 32), function () use (&$clock) {
            return $clock;
        });
        $receipt = $signer->issue($this->binding(), 'document', 1100);
        foreach ([999, 1100, 1101] as $clock) {
            try {
                $signer->read($receipt, 'document');
                $this->fail('Outside validity interval accepted.');
            } catch (IdentityTransitionConflict) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_no_unbounded_lifetime_or_unsigned_extra_authority_fields(): void
    {
        $signer = new IdentityReceipt(str_repeat('k', 32), fn () => 1000);
        try {
            $signer->issue([...$this->binding(), 'grant' => 'owner'], 'document', 1100);
            $this->fail('Additional authority field accepted.');
        } catch (IdentityTransitionConflict) {
            $this->assertTrue(true);
        }
        $this->expectException(InvalidArgumentException::class);
        $signer->issue($this->binding(), 'document', 8201);
    }
}
