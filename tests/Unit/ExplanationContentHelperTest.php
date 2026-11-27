<?php

namespace Tests\Unit;

use App\Support\ExplanationContentHelper;
use PHPUnit\Framework\TestCase;

class ExplanationContentHelperTest extends TestCase
{
    public function test_missing_values_are_classified_as_missing(): void
    {
        $missingCases = [
            null,
            '',
            '   ',
            "\t\n\r ",
            "\u{00A0}",
            '&nbsp;',
            '&#160;',
            '<p></p>',
            '<p><br></p>',
            '<p><br /></p>',
            '<p>&nbsp;</p>',
            '<p>   </p>',
            '<div><p><br></p><p>&nbsp; </p></div>',
            '<p><span data-type="inline-math" data-latex="   "></span></p>',
            '<p><img src=""></p>',
        ];

        foreach ($missingCases as $index => $value) {
            $this->assertTrue(
                ExplanationContentHelper::isMissing($value),
                "Expected case #{$index} to be classified as missing."
            );
            $this->assertFalse(ExplanationContentHelper::hasContent($value));
        }
    }

    public function test_valid_text_math_and_image_html_are_classified_as_present(): void
    {
        $presentCases = [
            'Force equals mass times acceleration.',
            '<p>Force equals mass times acceleration.</p>',
            '<p>قوت کمیت اور اسراع کے حاصل ضرب کے برابر ہوتی ہے۔</p>',
            '<p><span data-type="inline-math" data-latex="F = ma"></span></p>',
            '<div data-type="block-math" data-latex="E = mc^2"></div>',
            '<p><span class="ql-formula" data-value="x^2 + 1"></span></p>',
            '<p><img src="https://cdn.thestudentstimes.com/diagrams/force.png"></p>',
        ];

        foreach ($presentCases as $index => $value) {
            $this->assertFalse(
                ExplanationContentHelper::isMissing($value),
                "Expected case #{$index} to be classified as present."
            );
            $this->assertTrue(ExplanationContentHelper::hasContent($value));
        }
    }

    public function test_missing_status_and_effective_language_combinations(): void
    {
        $this->assertSame('missing_both', ExplanationContentHelper::missingStatus(null, '<p><br></p>'));
        $this->assertSame('missing_en', ExplanationContentHelper::missingStatus('<p>&nbsp;</p>', '<p>موجود اردو وضاحت</p>'));
        $this->assertSame('missing_ur', ExplanationContentHelper::missingStatus('<p>Existing EN</p>', ''));
        $this->assertSame('complete', ExplanationContentHelper::missingStatus('<p>EN</p>', '<p>UR</p>'));

        $this->assertSame('both', ExplanationContentHelper::effectiveLanguage('both', null, '<p></p>'));
        $this->assertSame('en', ExplanationContentHelper::effectiveLanguage('both', '<p>&nbsp;</p>', '<p>UR</p>'));
        $this->assertSame('ur', ExplanationContentHelper::effectiveLanguage('both', '<p>EN</p>', '<p><br></p>'));
        $this->assertNull(ExplanationContentHelper::effectiveLanguage('both', '<p>EN</p>', '<p>UR</p>'));
        $this->assertNull(ExplanationContentHelper::effectiveLanguage('en', '<p>EN</p>', null));
        $this->assertSame('ur', ExplanationContentHelper::effectiveLanguage('ur', '<p>EN</p>', null));
    }
}
