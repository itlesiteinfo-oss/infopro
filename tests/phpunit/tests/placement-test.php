<?php
/**
 * In-content placement: paragraph splitting and per-profile offsets.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Placement;
use HorizonPress\NewsBar\Settings;

class Placement_Test extends HPRNB_Test_Case {

	private function content( int $count ): string {
		$out = '';
		for ( $i = 1; $i <= $count; $i++ ) {
			$out .= '<p>Paragraphe ' . $i . '.</p>' . "\n";
		}
		return $out;
	}

	private function inline( array $overrides = array() ): array {
		return array_merge( Settings::defaults(), $overrides );
	}

	public function test_paragraphs_split_after_every_closing_tag() {
		$chunks = Placement::paragraphs( $this->content( 4 ) );
		$this->assertCount( 5, $chunks, 'Four paragraphs plus the tail.' );
		$this->assertStringEndsWith( '</p>', trim( $chunks[0] ) );
		$this->assertStringContainsString( 'Paragraphe 1.', $chunks[0] );
		$this->assertStringContainsString( 'Paragraphe 4.', $chunks[3] );
		$this->assertSame( '', trim( $chunks[4] ), 'Nothing after the last paragraph here.' );

		// Upper-case and spaced closing tags count too; content without any is left whole.
		$this->assertCount( 3, Placement::paragraphs( '<p>a</P>x</p  >tail' ) );
		$this->assertCount( 1, Placement::paragraphs( 'No paragraph at all.' ) );
		$this->assertSame( array( 'No paragraph at all.' ), Placement::paragraphs( 'No paragraph at all.' ) );
	}

	public function test_offsets_of_each_anchor() {
		$total = 8;

		$after = $this->inline(
			array(
				'desktop_placement'        => 'inline',
				'desktop_inline_anchor'    => 'after',
				'desktop_inline_paragraph' => 3,
			)
		);
		$this->assertSame( 3, Placement::offsets( $after, $total )['d'], 'After the 3rd: three paragraphs closed.' );

		$before = $this->inline(
			array(
				'desktop_placement'        => 'inline',
				'desktop_inline_anchor'    => 'before',
				'desktop_inline_paragraph' => 3,
			)
		);
		$this->assertSame( 2, Placement::offsets( $before, $total )['d'], 'Before the 3rd: two closed.' );

		$end = $this->inline(
			array(
				'desktop_placement'        => 'inline',
				'desktop_inline_anchor'    => 'before_end',
				'desktop_inline_paragraph' => 2,
			)
		);
		$this->assertSame( 6, Placement::offsets( $end, $total )['d'], 'Two paragraphs left behind.' );

		// Clamped to the article: a number beyond its length lands at one of its ends.
		$far = $this->inline(
			array(
				'desktop_placement'        => 'inline',
				'desktop_inline_anchor'    => 'after',
				'desktop_inline_paragraph' => 30,
			)
		);
		$this->assertSame( $total, Placement::offsets( $far, $total )['d'] );
		$far_end = $this->inline(
			array(
				'desktop_placement'        => 'inline',
				'desktop_inline_anchor'    => 'before_end',
				'desktop_inline_paragraph' => 30,
			)
		);
		$this->assertSame( 0, Placement::offsets( $far_end, $total )['d'] );
		$first = $this->inline(
			array(
				'desktop_placement'        => 'inline',
				'desktop_inline_anchor'    => 'before',
				'desktop_inline_paragraph' => 1,
			)
		);
		$this->assertSame( 0, Placement::offsets( $first, $total )['d'], 'Before the first: at the very top.' );
	}

	public function test_a_profile_left_on_the_screen_asks_for_no_offset() {
		$settings = $this->inline(
			array(
				'mobile_placement'        => 'inline',
				'mobile_inline_paragraph' => 2,
			)
		);
		$offsets  = Placement::offsets( $settings, 8 );
		$this->assertNull( $offsets['d'], 'The desktop stays pinned to the screen.' );
		$this->assertSame( 2, $offsets['m'] );
		$this->assertTrue( Placement::wants_inline( $settings ) );
		$this->assertFalse( Placement::wants_inline( Settings::defaults() ), 'Pinned on both profiles by default.' );
	}

	public function test_content_filter_places_the_root_between_the_paragraphs() {
		$post = self::factory()->post->create( array( 'post_content' => $this->content( 6 ) ) );
		self::factory()->post->create( array( 'post_title' => 'Une actualité' ) );
		update_option(
			'hprnb_settings',
			$this->inline(
				array(
					'desktop_placement'        => 'inline',
					'desktop_inline_anchor'    => 'after',
					'desktop_inline_paragraph' => 2,
				)
			)
		);
		Settings::flush();

		$this->go_to( get_permalink( $post ) );
		$this->assertTrue( have_posts() );
		the_post();

		$html = apply_filters( 'the_content', get_the_content() );
		$this->assertStringContainsString( 'id="hprnb-root"', $html );
		$this->assertSame( 1, substr_count( $html, 'id="hprnb-root"' ), 'A single root, here and not in the footer.' );
		$this->assertTrue( \HorizonPress\NewsBar\Frontend::root_claimed() );

		// Exactly two paragraphs stand before it.
		$before = substr( $html, 0, strpos( $html, 'id="hprnb-root"' ) );
		$this->assertSame( 2, substr_count( $before, '</p>' ) );

		// The two profiles asking for two different paragraphs leave a slot for the phone.
		\HorizonPress\NewsBar\Frontend::reset();
		update_option(
			'hprnb_settings',
			$this->inline(
				array(
					'desktop_placement'        => 'inline',
					'desktop_inline_paragraph' => 2,
					'mobile_placement'         => 'inline',
					'mobile_inline_paragraph'  => 4,
				)
			)
		);
		Settings::flush();
		$this->go_to( get_permalink( $post ) );
		the_post();
		$html = apply_filters( 'the_content', get_the_content() );
		$this->assertStringContainsString( 'data-hprnb-slot="m"', $html );
		// The bar carries a <p> of its own (the label): count the article's paragraphs without it.
		$article = preg_replace( '#<div id="hprnb-root".*?</aside></div>#s', '', $html );
		$slot    = substr( $article, 0, strpos( $article, 'data-hprnb-slot="m"' ) );
		$this->assertSame( 4, substr_count( $slot, '</p>' ) );
	}

	public function test_content_without_a_paragraph_keeps_the_bar_in_the_footer() {
		// A list is left alone by wpautop, so this content really has no paragraph to hang on to.
		$post = self::factory()->post->create( array( 'post_content' => '<ul><li>Un seul point</li></ul>' ) );
		self::factory()->post->create( array( 'post_title' => 'Une actualité' ) );
		update_option( 'hprnb_settings', $this->inline( array( 'desktop_placement' => 'inline' ) ) );
		Settings::flush();

		$this->go_to( get_permalink( $post ) );
		the_post();
		$html = apply_filters( 'the_content', get_the_content() );
		$this->assertStringNotContainsString( 'id="hprnb-root"', $html );
		$this->assertFalse( \HorizonPress\NewsBar\Frontend::root_claimed(), 'The footer still gets its chance.' );
	}
}
