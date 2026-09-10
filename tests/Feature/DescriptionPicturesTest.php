<?php

namespace Tests\Feature;

use App\Integrations\Listings\RichDescription;
use App\Support\Catalog\DescriptionHtml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PICTURES INSIDE A DESCRIPTION SURVIVE THE TRIP TO A STORE (VENT2-129).
 *
 * Two faults, one symptom. A picture pasted into the editor was stored as
 * base64 text, which no marketplace can read; and TikTok's push ran
 * strip_tags() over the description, deleting every image, list and
 * heading before it was sent to a marketplace that documents thirty
 * pictures. Between them, an operator laid out a description and the store
 * showed a wall of text.
 */
class DescriptionPicturesTest extends TestCase
{
    use RefreshDatabase;

    /** A 1x1 PNG, the smallest thing that is really an image. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function test_a_pasted_picture_becomes_a_real_file_and_the_description_points_at_it(): void
    {
        Storage::fake('public');

        $html = DescriptionHtml::store('<p>Before</p><img src="data:image/png;base64,' . self::PNG . '"><p>After</p>');

        $this->assertStringNotContainsString('data:image/', $html,
            'A picture stored as text is a picture no marketplace can ever take.');
        $this->assertStringContainsString('<p>Before</p>', $html, 'The words either side are untouched.');
        $this->assertStringContainsString('<p>After</p>', $html);

        $files = Storage::disk('public')->allFiles('catalog/_pasted');
        $this->assertCount(1, $files, 'The picture is in the library, where every push reads from.');
        $this->assertStringContainsString(basename($files[0]), $html, 'And the description points at it.');
    }

    /** The same screenshot in ten products is one file, not ten. */
    public function test_the_same_picture_pasted_twice_is_stored_once(): void
    {
        Storage::fake('public');

        DescriptionHtml::store('<img src="data:image/png;base64,' . self::PNG . '">');
        DescriptionHtml::store('<p>Another product</p><img src="data:image/png;base64,' . self::PNG . '">');

        $this->assertCount(1, Storage::disk('public')->allFiles('catalog/_pasted'));
    }

    /**
     * A src that says image and is not one never reaches the library: the
     * type comes from a string the browser wrote, and this folder is read
     * by every push.
     */
    public function test_something_that_is_not_an_image_is_left_where_it_was(): void
    {
        Storage::fake('public');

        $html = DescriptionHtml::store('<img src="data:image/png;base64,' . base64_encode('<?php echo 1;') . '">');

        $this->assertSame([], Storage::disk('public')->allFiles('catalog/_pasted'));
        // Left alone rather than thrown away: a save must not lose the paste.
        $this->assertStringContainsString('data:image/png;base64,', $html);
    }

    public function test_tiktok_keeps_the_pictures_lists_and_headings_it_accepts(): void
    {
        $html = '<h2>Specs</h2><ul><li>One</li><li>Two</li></ul>'
            . '<p>Look</p><img src="https://example.test/a.png" alt="a">'
            . '<script>alert(1)</script><table><tr><td>no</td></tr></table>';

        $out = RichDescription::forTikTok($html);

        $this->assertStringContainsString('<h2>Specs</h2>', $out);
        $this->assertStringContainsString('<li>One</li>', $out);
        $this->assertStringContainsString('https://example.test/a.png', $out,
            'TikTok documents up to thirty pictures in a description; the push used to delete every one.');
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('<table', $out);
        // What the forbidden tags SAID is kept; only the tags go.
        $this->assertStringContainsString('no', $out);
    }

    public function test_tiktok_drops_a_picture_it_could_never_fetch(): void
    {
        $out = RichDescription::forTikTok('<p>Hi</p><img src="data:image/png;base64,' . self::PNG . '">');

        $this->assertStringNotContainsString('data:image/', $out,
            'A src the marketplace cannot fetch is not a picture to it, and megabytes of payload for nothing.');
        $this->assertStringContainsString('<p>Hi</p>', $out);
    }

    /**
     * AND THE PUSH ACTUALLY USES IT. The shaping being right is half the
     * fix; the other half is the push calling it, which is the wire that
     * was strip_tags() for as long as this bug existed.
     */
    public function test_the_tiktok_push_sends_the_shaped_description_and_no_longer_strips_it(): void
    {
        $src = (string) file_get_contents(base_path('extensions/tiktok/Services/TikTok/TikTokProductPush.php'));

        $this->assertStringContainsString('RichDescription::forTikTok(', $src,
            "TikTok's push must shape the description rather than flatten it.");
        $this->assertDoesNotMatchRegularExpression(
            '/\$desc\s*=\s*[^;]*strip_tags\s*\(\s*\$rawDesc/',
            $src,
            'The description is being flattened again; every picture, list and heading in it is deleted before it is sent.'
        );
    }

    public function test_tiktok_stops_at_thirty_pictures_and_keeps_the_words(): void
    {
        $html = '<p>Gallery</p>' . str_repeat('<img src="https://example.test/x.png">', 35);

        $out = RichDescription::forTikTok($html);

        $this->assertSame(RichDescription::TIKTOK_MAX_IMAGES, RichDescription::imageCount($out));
        $this->assertStringContainsString('<p>Gallery</p>', $out);
    }
}
