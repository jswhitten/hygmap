<?php
declare(strict_types=1);
session_start();

// load prefs
function cfg_load(): array
{
    return $_SESSION['cfg'] ?? [
        'unit'          => 'ly',
        'grid'          => 20,
        'fic_names'     => 0, // 0=none, other values are world IDs from fic_worlds table
        'image_type'    => 'normal',
        'image_size'    => 600,
        'max_line'      => 0,
        'm_limit'       => 20.0,
        'm_limit_label' => 8.0,
        'show_signals'  => true,
        'profiling'     => false, // Enable timing output for debugging
    ];

}

// save prefs
function cfg_set(array $new): void
{
    $_SESSION['cfg'] = array_merge(cfg_load(), $new);
}
