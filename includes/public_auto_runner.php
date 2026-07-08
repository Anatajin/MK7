<script>
(function() {
    // Auto Scraper Runner for Public Visitors
    // This allows the site to update itself using visitor traffic
    function triggerAutoScraper() {
        // Use a relative path that works from root or subfolders
        // Assuming this is included in pages at root like index.php
        const apiUrl = 'api/auto_scraper.php'; 
        
        // If we are in a subfolder (like admin), adjust path, though this is mainly for public pages
        // A more robust way is to use absolute path if possible, or detect location
        const path = window.location.pathname;
        const basePath = path.substring(0, path.lastIndexOf('/'));
        const fullApiUrl = window.location.origin + basePath + '/api/auto_scraper.php';

        // Use sendBeacon if available for background sending, or fetch
        if (navigator.sendBeacon) {
            // sendBeacon is better for "fire and forget" but requires blob/form data usually
            // simple fetch is often enough for this
        }

        fetch('api/auto_scraper.php', { method: 'GET', cache: 'no-cache' })
            .then(response => {
                // We don't need to do anything with the response
                // console.log('Background update check triggered');
            })
            .catch(e => {
                // Silent fail
            });
    }

    // Run once 2 seconds after page load to not impact initial load performance
    setTimeout(triggerAutoScraper, 2000);
})();
</script>
