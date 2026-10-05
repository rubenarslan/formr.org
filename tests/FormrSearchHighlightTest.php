<?php

/**
 * formr_search_highlight() marks the admin's search in an account's email,
 * in the user list (templates/admin/advanced/user_management.php). Both
 * strings are text: an email may hold markup in a quoted local part, and
 * the search comes from the URL.
 */
class FormrSearchHighlightTest extends \PHPUnit\Framework\TestCase {

    public function testTheEmailIsText() {
        $email = '"<form/action=//evil.example>"@example.org';
        $this->assertNotFalse(filter_var($email, FILTER_VALIDATE_EMAIL), 'such an address is accepted at signup');
        $out = formr_search_highlight('', $email);
        $this->assertStringNotContainsString('<form', $out);
        $this->assertStringContainsString('&lt;form/action=//evil.example&gt;', $out);
    }

    public function testTheSearchIsText() {
        $out = formr_search_highlight('<img src=x onerror=alert(1)>', 'someone@example.org');
        $this->assertStringNotContainsString('<img', $out);
    }

    public function testAMatchIsHighlighted() {
        $this->assertSame('a<span class="search-highlight">@b</span>.org', formr_search_highlight('@b', 'a@b.org'));
        $this->assertSame('&quot;&lt;<span class="search-highlight">x</span>&gt;&quot;@b.org', formr_search_highlight('x', '"<x>"@b.org'));
    }
}
