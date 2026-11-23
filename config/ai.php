<?php

declare(strict_types=1);

return [
    'default_provider' => env('AI_DEFAULT_PROVIDER', 'mock'),
    'embedding_dimensions' => (int) env('AI_EMBEDDING_DIMENSIONS', 1536),
    'vector_driver' => env('AI_VECTOR_DRIVER', 'auto'), // auto | native | json

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'default_embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
            'default_chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
        ],
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'default_embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'text-embedding-004'),
            'default_chat_model' => env('GEMINI_CHAT_MODEL', 'gemini-2.5-flash'),
        ],
    ],

    'storage' => [
        'connection_string' => env('AZURE_STORAGE_CONNECTION_STRING', ''),
        'container' => env('AZURE_STORAGE_CONTAINER', 'nexamind-documents'),
        'public_blob_endpoint' => env('AZURE_STORAGE_PUBLIC_ENDPOINT', 'http://127.0.0.1:10000/devstoreaccount1'),
    ],
];
