<?php

namespace App\DataFixtures;

use App\Entity\Listing;
use App\Enum\Category;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Draws stand-in listing photos for the example data: a book cover for
 * textbooks, a plain card with the item's name for everything else.
 * Dev only; it uses a system TrueType font when it finds one.
 */
final class PlaceholderPhotos
{
    private const array FONTS = [
        '/System/Library/Fonts/Supplemental/Georgia Bold.ttf',
        '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
    ];

    /** Cover colours, picked by title so each book keeps its look between seeds. */
    private const array COVERS = [
        [11, 58, 159], [140, 29, 43], [22, 101, 82], [74, 44, 122], [196, 92, 22], [31, 41, 55], [12, 116, 137],
    ];

    private ?string $font = null;

    public function __construct()
    {
        foreach (self::FONTS as $font) {
            if (is_readable($font)) {
                $this->font = $font;
                break;
            }
        }
    }

    /**
     * @param int $variant 0 for the main photo; others are angled "back" and "detail" shots
     */
    public function for(Listing $listing, int $variant = 0): File
    {
        $image       = imagecreatetruecolor(1200, 900);
        [$r, $g, $b] = self::COVERS[abs(crc32((string) $listing->getTitle())) % \count(self::COVERS)];

        // A table-top background, a little different per photo.
        $shade = 236 - 10 * $variant;
        imagefill($image, 0, 0, imagecolorallocate($image, $shade, $shade - 4, $shade - 12));

        if ($listing->getCategory() === Category::Textbooks) {
            $this->drawBook($image, $listing, [$r, $g, $b], $variant);
        } else {
            $this->drawCard($image, $listing, [$r, $g, $b]);
        }

        $path = tempnam(sys_get_temp_dir(), 'stb-placeholder') . '.png';
        imagepng($image, $path);

        return new File($path);
    }

    /** @param array{int, int, int} $rgb */
    private function drawBook(\GdImage $image, Listing $listing, array $rgb, int $variant): void
    {
        [$r, $g, $b] = $rgb;
        $x           = 330 + 30 * $variant;
        $y           = 90;
        $width       = 540;
        $height      = 720;

        // Shadow, cover, spine and page edge.
        imagefilledrectangle($image, $x + 18, $y + 18, $x + $width + 18, $y + $height + 18, imagecolorallocatealpha($image, 0, 0, 0, 90));
        imagefilledrectangle($image, $x, $y, $x + $width, $y + $height, imagecolorallocate($image, $r, $g, $b));
        imagefilledrectangle($image, $x, $y, $x + 34, $y + $height, imagecolorallocate($image, (int) ($r * .7), (int) ($g * .7), (int) ($b * .7)));
        imagefilledrectangle($image, $x + $width - 8, $y + 8, $x + $width, $y + $height - 8, imagecolorallocate($image, 246, 242, 230));

        $white = imagecolorallocate($image, 255, 255, 255);
        $gold  = imagecolorallocate($image, 255, 205, 0);
        imagefilledrectangle($image, $x + 70, $y + 90, $x + 190, $y + 98, $gold);

        if ($variant !== 0) {
            // The "back cover": just a barcode block.
            imagefilledrectangle($image, $x + 330, $y + 560, $x + 480, $y + 650, $white);

            return;
        }

        $bottom = $this->text($image, (string) $listing->getTitle(), 40, $white, $x + 70, $y + 170, $width - 130);
        if (null !== $book = $listing->getBook()) {
            $this->text($image, (string) $book->getAuthor(), 20, imagecolorallocatealpha($image, 255, 255, 255, 30), $x + 70, $bottom + 30, $width - 130);
            if ($book->getEdition() !== null) {
                $this->text($image, $book->getEditionOrdinal() . ' edition', 20, $gold, $x + 70, $y + $height - 80, $width - 130);
            }
        }
    }

    /** @param array{int, int, int} $rgb */
    private function drawCard(\GdImage $image, Listing $listing, array $rgb): void
    {
        [$r, $g, $b] = $rgb;
        imagefilledrectangle($image, 0, 620, 1200, 900, imagecolorallocate($image, $r, $g, $b));
        imagefilledellipse($image, 600, 380, 420, 420, imagecolorallocate($image, min(255, $r + 120), min(255, $g + 120), min(255, $b + 120)));
        $label = $listing->getCategory()?->label() ?? '';
        $box   = $this->font === null ? [0, 0, 8 * \strlen($label)] : imagettfbbox(30, 0, $this->font, $label);
        $this->text($image, $label, 30, imagecolorallocate($image, $r, $g, $b), (int) (600 - ($box[2] - $box[0]) / 2), 360, 400);
        $this->text($image, (string) $listing->getTitle(), 44, imagecolorallocate($image, 255, 255, 255), 80, 690, 1040);
    }

    /**
     * Wrapped text; returns the y of the last line's baseline.
     */
    private function text(\GdImage $image, string $text, int $size, int $colour, int $x, int $y, int $width): int
    {
        if ($this->font === null) {
            // No TrueType font: GD's built-in one, unwrapped.
            imagestring($image, 5, $x, $y, $text, $colour);

            return $y + 20;
        }

        $line = '';
        $y += $size;
        foreach (explode(' ', $text) as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            $box = imagettfbbox($size, 0, $this->font, $try);
            if ($line !== '' && $width < $box[2] - $box[0]) {
                imagettftext($image, $size, 0, $x, $y, $colour, $this->font, $line);
                $y += (int) ($size * 1.4);
                $line = $word;
            } else {
                $line = $try;
            }
        }
        imagettftext($image, $size, 0, $x, $y, $colour, $this->font, $line);

        return $y;
    }
}
