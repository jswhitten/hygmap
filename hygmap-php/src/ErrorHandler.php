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
    public static function handleError(string $userMessage, \Exception $e): never
    {
        // Log the technical error
        error_log("HYGMap Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());

        // Display user-friendly message
        echo '<!DOCTYPE html><html><head><title>Error</title>';
        echo '<style>body{font-family:sans-serif;max-width:600px;margin:2rem auto;padding:1rem;}';
        echo '.error{background:#fee;border:2px solid #c00;padding:1rem;border-radius:4px;}';
        echo 'h1{color:#c00;}</style></head><body>';
        echo '<div class="error"><h1>⚠️ Error</h1>';
        echo '<p>' . htmlspecialchars($userMessage, ENT_QUOTES) . '</p>';
        echo '<p><a href="index.php">← Return to map</a></p>';
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
