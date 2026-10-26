<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Another process is generating this image right now. Not a failure of the
 * image: callers retry later instead of recording it as broken.
 */
final class ImageProcessingBusy extends RuntimeException
{
}
