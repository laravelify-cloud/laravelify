{{-- Tell the panel the browser's timezone and language, so times show in local time and format. --}}
<script>
    (function () {
        var follows = @js(app(\App\Services\DisplayTime::class)->followsBrowser());
        var zone = 'UTC';
        var locale = 'en';

        try {
            zone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
            locale = (navigator.languages && navigator.languages[0]) || navigator.language || 'en';
        } catch (e) {}

        function read(name) {
            var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));

            return match ? decodeURIComponent(match[1]) : null;
        }

        var changed = read(@js(\App\Services\DisplayTime::TIMEZONE_COOKIE)) !== zone || read(@js(\App\Services\DisplayTime::LOCALE_COOKIE)) !== locale;
        var attributes = '; path=/; max-age=31536000; samesite=lax' + (location.protocol === 'https:' ? '; secure' : '');

        document.cookie = @js(\App\Services\DisplayTime::TIMEZONE_COOKIE) + '=' + encodeURIComponent(zone) + attributes;
        document.cookie = @js(\App\Services\DisplayTime::LOCALE_COOKIE) + '=' + encodeURIComponent(locale) + attributes;

        // The first visit (or a new timezone): reload once so this page already uses local time.
        if (changed && follows) {
            try {
                if (sessionStorage.getItem('laravelify-time-reloaded') !== zone + locale) {
                    sessionStorage.setItem('laravelify-time-reloaded', zone + locale);
                    location.reload();
                }
            } catch (e) {}
        }
    })();
</script>
