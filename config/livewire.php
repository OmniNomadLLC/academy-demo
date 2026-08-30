<?php

return [
    'class_namespace' => 'App\\Livewire',
    'view_path' => resource_path('views/livewire'),
    'layout' => null,
    'lazy_placeholder' => null,
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMP_DISK', 'local'),
        'rules' => null,
        'directory' => env('LIVEWIRE_TEMP_DIRECTORY', 'livewire-tmp'),
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],
    'render_on_redirect' => false,
    'legacy_model_binding' => false,
    'inject_assets' => true,
    'navigate' => [
        'show_progress_bar' => true,
        'progress_bar_color' => '#2299dd',
    ],
    'inject_morph_markers' => true,
    'pagination_theme' => 'tailwind',
];
