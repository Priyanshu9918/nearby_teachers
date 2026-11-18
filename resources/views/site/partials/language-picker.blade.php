<form method="get" action="{{ request()->url() }}" class="lang-picker site-language-picker" style="max-width:760px;margin:24px auto;padding:16px 20px">
    @foreach (request()->except(['lang', 'page']) as $key => $value)
        @if (is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
    @endforeach
    <label for="site-language">{{ data_get($t, 'settings.language', $labels['language'] ?? 'Language') }}</label>
    <select id="site-language" name="lang" onchange="document.cookie='nt_lang='+encodeURIComponent(this.value)+';path=/;max-age=31536000;SameSite=Lax';this.form.submit()">
        @foreach (\App\Support\PublicLanguages::all() as $option)
            <option value="{{ $option['code'] }}" @selected(($language ?? $lang) === $option['code'])>{{ $option['nativeName'] }}</option>
        @endforeach
    </select>
</form>
<script>
    (() => {
        const selectedLanguage = @js($language ?? $lang);
        if (new URLSearchParams(window.location.search).has('lang')) {
            document.cookie = 'nt_lang=' + encodeURIComponent(selectedLanguage) + ';path=/;max-age=31536000;SameSite=Lax';
        }
        document.querySelectorAll('select[name="lang"]').forEach(select => {
            select.addEventListener('change', () => {
                document.cookie = 'nt_lang=' + encodeURIComponent(select.value) + ';path=/;max-age=31536000;SameSite=Lax';
            });
        });
    })();
</script>
