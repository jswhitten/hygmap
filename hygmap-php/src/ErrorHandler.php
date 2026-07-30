<?php
declare(strict_types=1);

/**
 * Error handling utilities
 *
 * Provides user-friendly error display for both HTML pages and image generation,
 * while logging technical details for debugging.
 */
final class ErrorHandler
{
    /**
     * Display a user-friendly error message and log the technical details
     *
     * Outputs an HTML error page with the user message and logs
     * the full exception details to the error log.
     *
     * @param string $userMessage User-friendly message to display
     * @param \Exception $e The exception that occurred
     * @return never
     */
    public static function handleError(
        string $userMessage,
        \Exception $e,
        int $statusCode = 503,
        string $heading = 'Service Unavailable'
    ): never {
        // Log the technical error
        error_log("HYGMap Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());

        self::sendErrorPage($statusCode, $heading, $userMessage);
    }

    /**
     * The requested thing does not exist. Answers 404.
     *
     * Distinct from handleError on purpose: a bad star id in a URL is the user's typo or
     * a stale bookmark, not a fault. Telling them the service is broken sends them to
     * retry something that will never work, and a 200 on this page tells crawlers and
     * monitors that a missing star is a valid page.
     *
     * @param string $userMessage User-friendly message to display
     * @return never
     */
    public static function handleNotFound(string $userMessage): never
    {
        self::sendErrorPage(404, 'Not Found', $userMessage);
    }

    /**
     * Emit an error page with a real HTTP status.
     *
     * The status is the point. This page used to always be 200, so an outage and a
     * missing star were indistinguishable to anything that was not a human reading the
     * prose.
     *
     * @return never
     */
    private static function sendErrorPage(int $statusCode, string $heading, string $userMessage): never
    {
        if (!headers_sent()) {
            http_response_code($statusCode);
        }

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>';
        echo htmlspecialchars($heading, ENT_QUOTES) . '</title>';
        echo '<style>body{font-family:sans-serif;max-width:600px;margin:2rem auto;padding:1rem;}';
        echo '.error{background:#fee;border:2px solid #c00;padding:1rem;border-radius:4px;}';
        echo 'h1{color:#c00;}</style></head><body>';
        echo '<div class="error"><h1>' . htmlspecialchars($heading, ENT_QUOTES) . '</h1>';
        echo '<p>' . htmlspecialchars($userMessage, ENT_QUOTES) . '</p>';
        echo '<p><a href="/">Return to map</a></p>';
        echo '</div></body></html>';
        exit;
    }

    /**
     * Create an error image instead of crashing
     *
     * Outputs a PNG image with the error message for contexts
     * where image output is expected (e.g., map.php).
     *
     * @param string $message Error message to display on the image
     * @return never
     */
    public static function createErrorImage(string $message): never
    {
        $image = imagecreate(400, 100);
        if ($image === false) {
            // Fallback if we can't even create the error image
            header("Content-type: text/plain");
            echo "Error: " . $message;
            exit;
        }

        $bg = imagecolorallocate($image, 255, 240, 240);
        $text = imagecolorallocate($image, 200, 0, 0);
        imagefill($image, 0, 0, $bg);
        imagestring($image, 3, 10, 40, $message, $text);

        header("Content-type: image/png");
        imagepng($image);
        imagedestroy($image);
        exit;
    }
}
