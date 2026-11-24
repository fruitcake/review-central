<?php

declare(strict_types=1);

namespace App\Filament\Components;

use App\Connections\Integrations;
use App\Models\IntegrationApp;
use App\Support\SetupGuides;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * The form of an app, for the platform's list and a tenant's own: its type,
 * name and what it is made of, with the guide that says where it is made. A
 * secret is kept encrypted, never sent back, and replaced only when typed.
 */
class IntegrationAppForm
{
    /** The guide for each type of app. */
    public const GUIDES = [
        'google' => SetupGuides::GOOGLE,
        'bitbucket' => SetupGuides::BITBUCKET,
        'asana' => SetupGuides::ASANA,
        'atlassian' => SetupGuides::ATLASSIAN,
        'github_app' => SetupGuides::GITHUB_APP,
    ];

    /**
     * @param  array<string, string>|Closure  $types  the types that may be picked, type => label
     * @return array<int, Field|Section>
     */
    public static function fields(array|Closure $types): array
    {
        return [
            Select::make('type')
                ->options($types)
                ->required()
                ->live()
                ->disabledOn('edit')
                ->afterStateUpdated(fn (Set $set, ?string $state) => $set('name', $state === null ? null : Integrations::TYPES[$state]['service']))
                ->hintAction(SetupGuideAction::make(fn (Get $get, SetupGuides $guides) => isset(self::GUIDES[$get('type')]) ? $guides->named(self::GUIDES[$get('type')]) : null, 'appGuide')),
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->helperText('What a person picks it by when connecting, where there is more than one.'),
            Section::make('Credentials')
                ->description('Stored encrypted, and never shown again. Left empty on a change, a secret keeps what it was.')
                ->statePath('credentials')
                ->columns(2)
                ->visible(fn (Get $get): bool => filled($get('type')))
                ->schema(fn (Get $get, ?IntegrationApp $record): array => collect(Integrations::TYPES[$get('type')]['fields'] ?? [])
                    ->map(fn (array $field, string $key): Field => self::field($key, $field, $record))
                    ->values()
                    ->all()),
        ];
    }

    /**
     * @param  array{label: string, secret: bool, env: string}  $field
     */
    protected static function field(string $key, array $field, ?IntegrationApp $record): Field
    {
        $saved = $record?->credential($key) !== null;

        if ($key === 'private_key') {
            return Textarea::make($key)
                ->label($field['label'])
                ->rows(3)
                ->required(! $saved)
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (filled($value) && ! str_contains((string) $value, '-----BEGIN')) {
                        $fail('The contents of the .pem file GitHub made, from -----BEGIN to the end.');
                    }
                })
                ->placeholder($saved ? 'Saved; paste to replace' : '-----BEGIN RSA PRIVATE KEY-----')
                ->columnSpanFull();
        }

        $input = TextInput::make($key)->label($field['label'])->maxLength(1000)->required(! $field['secret'] || ! $saved);

        return $field['secret']
            ? $input->password()->revealable()->autocomplete('off')->placeholder($saved ? 'Saved; type to replace' : null)
            : $input;
    }

    /**
     * The form's values from an app: what it is made of, its secrets left out.
     * They are hidden on the model, so they are read from the app itself.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fill(array $data, IntegrationApp $record): array
    {
        $type = $record->type;
        $credentials = $record->credentials;

        foreach (Integrations::TYPES[$type]['fields'] ?? [] as $key => $field) {
            if ($field['secret']) {
                $credentials[$key] = null;
            }
        }

        return ['credentials' => $credentials] + $data;
    }

    /**
     * What is saved from the form: a secret left empty keeps what it was, and only
     * the type's own fields are kept.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function save(array $data, ?IntegrationApp $record = null): array
    {
        $type = $data['type'] ?? $record?->type;
        $given = is_array($data['credentials'] ?? null) ? $data['credentials'] : [];
        $credentials = [];

        foreach (Integrations::TYPES[$type]['fields'] ?? [] as $key => $field) {
            $value = is_string($given[$key] ?? null) ? trim($given[$key]) : '';
            $value = $value === '' ? $record?->credential($key) : $value;

            if ($value !== null) {
                $credentials[$key] = $value;
            }
        }

        return ['credentials' => $credentials] + $data;
    }
}
