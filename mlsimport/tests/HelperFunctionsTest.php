<?php
use PHPUnit\Framework\TestCase;

class HelperFunctionsTest extends TestCase {
    public function test_sanitize_multi_dimensional_array() {
        $input = [ 'k' => ' <b>v</b> ', 'n' => [ 'v' => "a\\b" ] ];
        $expected = [ 'k' => 'v', 'n' => [ 'v' => 'ab' ] ];
        $this->assertSame( $expected, mlsimport_sanitize_multi_dimensional_array( $input ) );
    }

    public function test_allowed_html_tags_contains_select() {
        $allowed = mlsimport_allowed_html_tags_content();
        $this->assertArrayHasKey( 'select', $allowed );
        $this->assertArrayHasKey( 'option', $allowed );
    }
}
