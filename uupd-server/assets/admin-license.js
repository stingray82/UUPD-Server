document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-uupd-child-browser]').forEach(function (browser) {
        var search = browser.querySelector('[data-uupd-child-search]');
        var status = browser.querySelector('[data-uupd-child-status]');
        var sites = browser.querySelector('[data-uupd-child-sites]');
        var rows = Array.from(browser.querySelectorAll('[data-uupd-child-row]'));
        var count = browser.querySelector('[data-uupd-child-count]');
        var empty = browser.querySelector('[data-uupd-child-empty]');

        function filterRows() {
            var query = search.value.trim().toLocaleLowerCase();
            var visible = 0;
            rows.forEach(function (row) {
                var matches = (!query || row.dataset.uupdSearch.toLocaleLowerCase().includes(query)) &&
                    (status.value === 'all' || row.dataset.uupdStatus === status.value) &&
                    (sites.value === 'all' || row.dataset.uupdConnected === (sites.value === 'connected' ? '1' : '0'));
                row.hidden = !matches;
                if (matches) { visible++; }
            });
            count.textContent = visible + ' of ' + rows.length + ' child keys shown';
            empty.hidden = visible !== 0;
        }

        search.addEventListener('input', filterRows);
        status.addEventListener('change', filterRows);
        sites.addEventListener('change', filterRows);
        filterRows();
    });
});
