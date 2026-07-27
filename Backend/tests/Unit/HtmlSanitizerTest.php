<?php

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase
{
    public function test_removes_script_tags_and_their_content(): void
    {
        $out = HtmlSanitizer::clean('<p>Hola</p><script>alert(1)</script>');
        $this->assertStringContainsString('Hola', $out);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
    }

    public function test_strips_event_handler_attributes(): void
    {
        $out = HtmlSanitizer::clean('<img src="x" onerror="alert(1)">');
        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
    }

    public function test_strips_javascript_scheme_in_href(): void
    {
        $out = HtmlSanitizer::clean('<a href="javascript:alert(1)">click</a>');
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringContainsString('click', $out);
    }

    public function test_strips_data_html_scheme(): void
    {
        $out = HtmlSanitizer::clean('<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>');
        $this->assertStringNotContainsString('data:text/html', $out);
    }

    public function test_keeps_allowed_formatting_tags(): void
    {
        $out = HtmlSanitizer::clean('<h2>Título</h2><p><strong>negrita</strong> y <em>cursiva</em></p><ul><li>uno</li></ul>');
        $this->assertStringContainsString('<h2>', $out);
        $this->assertStringContainsString('<strong>', $out);
        $this->assertStringContainsString('<em>', $out);
        $this->assertStringContainsString('<li>', $out);
    }

    public function test_preserves_wiki_link_markup_generated_by_the_app(): void
    {
        // El propio ObsidianController genera estos enlaces internos; el sanitizador
        // no debe romperlos (class, data-target-slug, href="#" son seguros).
        $wiki = '<a href="#" class="wiki-link" data-target-slug="mi-doc">Mi Doc</a>';
        $out = HtmlSanitizer::clean($wiki);
        $this->assertStringContainsString('data-target-slug="mi-doc"', $out);
        $this->assertStringContainsString('class="wiki-link"', $out);
        $this->assertStringContainsString('Mi Doc', $out);
    }

    public function test_keeps_safe_external_links_and_images(): void
    {
        $out = HtmlSanitizer::clean('<a href="https://ejemplo.com">link</a><img src="https://ejemplo.com/a.png" alt="foto">');
        $this->assertStringContainsString('https://ejemplo.com', $out);
        $this->assertStringContainsString('a.png', $out);
        $this->assertStringContainsString('alt="foto"', $out);
    }

    public function test_unwraps_unknown_but_harmless_tags_keeping_text(): void
    {
        $out = HtmlSanitizer::clean('<marquee>desliza</marquee>');
        $this->assertStringNotContainsString('<marquee', $out);
        $this->assertStringContainsString('desliza', $out);
    }

    public function test_removes_iframe_entirely(): void
    {
        $out = HtmlSanitizer::clean('<p>antes</p><iframe src="https://evil.com"></iframe><p>después</p>');
        $this->assertStringNotContainsString('<iframe', $out);
        $this->assertStringContainsString('antes', $out);
        $this->assertStringContainsString('después', $out);
    }

    public function test_empty_input_returns_empty_string(): void
    {
        $this->assertSame('', HtmlSanitizer::clean(null));
        $this->assertSame('', HtmlSanitizer::clean('   '));
    }
}
