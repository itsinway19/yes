<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Mime\Header\ParameterizedHeader;

final class MimeHeaderParameterSafetyTest extends TestCase
{
    public static function nonTokenNames(): array
    {
        return ['empty' => [''], 'space' => ['bad name'], 'colon' => ['bad:name'], 'semicolon' => ['bad;name'],
            'quote' => ['bad"name'], 'line break' => ["filename\r\nX-Injected"], 'null byte' => ["file\0name"], 'non ASCII name' => ['اسم']];
    }

    #[DataProvider('nonTokenNames')]
    public function test_constructor_rejects_non_token_parameter_names(string $name): void
    {
        $this->expectException(RfcComplianceException::class);
        new ParameterizedHeader('Content-Disposition', 'attachment', [$name => 'probe.txt']);
    }

    #[DataProvider('nonTokenNames')]
    public function test_single_parameter_rejects_non_token_names(string $name): void
    {
        $header = new ParameterizedHeader('Content-Disposition', 'attachment', ['filename' => 'safe.txt']);
        $this->expectException(RfcComplianceException::class);
        $header->setParameter($name, 'probe.txt');
    }

    #[DataProvider('nonTokenNames')]
    public function test_bulk_rejection_keeps_the_prior_valid_header(string $name): void
    {
        $header = new ParameterizedHeader('Content-Disposition', 'attachment', ['filename' => 'safe.txt']);
        $before = $header->toString();
        try {
            $header->setParameters(['filename' => 'replacement.txt', $name => 'probe.txt']);
            $this->fail('Invalid MIME parameter name was accepted.');
        } catch (RfcComplianceException) {
            $this->assertSame(['filename' => 'safe.txt'], $header->getParameters());
            $this->assertSame($before, $header->toString());
        }
    }

    public function test_valid_arabic_filename_remains_available_and_is_encoded_for_transport(): void
    {
        $filename = 'تقرير النشاط.pdf';
        $header = new ParameterizedHeader('Content-Disposition', 'attachment', ['filename' => $filename]);
        $this->assertSame($filename, $header->getParameter('filename'));
        $this->assertStringContainsString('filename*', $header->toString());
        $this->assertStringNotContainsString($filename, $header->toString());
    }
}
