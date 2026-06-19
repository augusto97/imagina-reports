<?php

declare(strict_types=1);

namespace App\Connectors;

/**
 * Input control type for a connector config field. Drives the admin data-source
 * configuration form (CLAUDE.md §7 configSchema / §11.1).
 */
enum ConfigFieldType: string
{
    case Text = 'text';
    case Password = 'password';
    case Url = 'url';
    case Number = 'number';
    case Textarea = 'textarea';
    case Json = 'json';
}
