<?php
declare(strict_types=1);

require 'bootstrap.php';
require_once 'MapRenderer.php';

// Variables defined in bootstrap.php
/** @var array $cfg */
/** @var array $vars */

// Fetching lives here, drawing lives in MapRenderer.
//
// The renderer used to call ApiClient itself, which made it impossible to exercise without a
// live API -- so nothing ever did, and a blank map was indistinguishable from a correct one.
// Splitting them also puts data-failure handling where the rest of the app keeps it.
$renderer = new MapRenderer($cfg, $vars);
$bbox = $renderer->buildBoundingBox();

try {
    $stars = ApiClient::instance()->queryAll(
        $bbox,
        (float)$cfg['m_limit'],
        (int)$cfg['fic_names'],
        'absmag desc'
    );
} catch (RuntimeException $e) {
    error_log('Map generation error: ' . $e->getMessage());
    // An image is expected here, so answer with one rather than an HTML error page: this is
    // the src of an <img>, and a 4xx/5xx would render as a broken-image icon with no
    // explanation.
    ErrorHandler::createErrorImage('API error - unable to load stars');
}

try {
    $signals = ((bool)$cfg['show_signals']) ? ApiClient::instance()->querySignals($bbox) : [];
} catch (RuntimeException $e) {
    // Signals are decoration; a map without them still answers the question being asked.
    error_log('Signal query error: ' . $e->getMessage());
    $signals = [];
}

$renderer->render($stars, $signals);
