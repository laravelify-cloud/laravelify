<script>
    function laravelifyTheme(theme) {
        if (theme) {
            localStorage.setItem('laravelify-theme', theme);
            document.cookie = 'laravelify-theme=' + theme + ';path=/;max-age=31536000;SameSite=Lax';
        }

        theme = theme || localStorage.getItem('laravelify-theme') || 'dark';
        document.documentElement.classList.toggle('dark', theme !== 'light');
    }

    laravelifyTheme();
    document.addEventListener('livewire:navigated', function () {
        laravelifyTheme();
    });
</script>
