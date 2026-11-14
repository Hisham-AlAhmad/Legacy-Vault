<x-mail::message>
{{ __('legacyvault.mail.password_reset_intro', [], $locale) }}

<x-mail::button :url="$resetUrl">
{{ __('legacyvault.mail.password_reset_button', [], $locale) }}
</x-mail::button>

{{ __('legacyvault.mail.password_reset_vault_note', [], $locale) }}

{{ __('legacyvault.mail.password_reset_ignore', [], $locale) }}
</x-mail::message>
